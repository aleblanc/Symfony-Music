// RhythmGameEngine — the full client-side game engine, extracted
// VERBATIM from legacy/php_music.php (lines 80990-85989, exactly
// 5000 lines). This is the ENTIRE game logic: note scroll/render
// loop (canvas), input handling (keyboard + touch), hit-window
// judging (perfect/great/good/bad/miss), combo/scoring, hold-note
// and swipe-note mechanics, audio playback sync, results screen,
// leaderboard fetch/render, settings (density, calibration offset
// in ms), song browser/search/favorites/artist-detail tabs on the
// hub screen, and posting the finished run to save_rhythm_score
// (see backend-dispatcher-cases.php).
//
// This is framework-agnostic vanilla JS (no jQuery/React dependency
// visible in this excerpt) — directly reusable as the client engine
// in the Symfony port (as an AssetMapper-managed JS module), only
// the DOM query selectors / fetch() URLs / CSRF handling need
// updating to match Symfony routes.
//
// Calibration Tool (search 'calibration' below): lets the player
// adjust an audio-offset-in-ms setting — this compensates for THEIR
// device/speaker latency, it is NOT related to how charts are
// generated server-side (charts are pseudo-random, not derived from
// the real beat — see chart-generation-functions.php).

(function () {
    // ----------------------------------------------------------------------
    // Symfony integration shim for the ported rhythm engine (Phase 1).
    // The engine below is a verbatim copy of the legacy client, which talked
    // to a `?action=...` PHP dispatcher and relied on a few globals provided
    // by the monolith scope (currentUser, escapeHTML, showToast, bootstrap,
    // fetchData). We recreate those here and map the legacy URLs to the
    // Symfony routes rendered in #rhythm-config, injecting the CSRF token on
    // writes. Wrapped in a page guard so it is a no-op outside /rhythm and
    // safe to re-run after each partial-navigation swap (see nav.js).
    // ----------------------------------------------------------------------
    const hub = document.getElementById('rg-screen-hub');
    const cfgEl = document.getElementById('rhythm-config');
    if (!hub || !cfgEl) {
        return;
    }

    const RG = JSON.parse(cfgEl.textContent);

    // Legacy globals the extracted engine expects.
    const currentUser = RG.currentUser;

    // Legacy SPA view-state global. Only read by the engine (deep-link restore
    // + stored in history.pushState state at startGame). An empty object keeps
    // the guarded deep-link branches inert and stops the bare read from throwing.
    let currentView = {};

    const escapeHTML = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const showToast = (msg) => { console.log('[rhythm]', msg); };

    // Cover-art fallback: a deterministic initial-on-colour SVG data URI, used
    // as the <img onerror> src. Legacy provided this global.
    const getSvgPlaceholder = (title) => {
        const t = String(title || '?').trim();
        const letter = escapeHTML((t[0] || '?').toUpperCase());
        let h = 0;
        for (let i = 0; i < t.length; i++) h = (h * 31 + t.charCodeAt(i)) % 360;
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160">'
            + '<rect width="160" height="160" fill="hsl(' + h + ',45%,30%)"/>'
            + '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="80" fill="rgba(255,255,255,0.85)">' + letter + '</text></svg>';
        return 'data:image/svg+xml,' + encodeURIComponent(svg);
    };

    // Compact count formatter (plays / followers). Legacy provided this global.
    const formatSongCount = (n) => {
        n = Number(n) || 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return String(n);
    };

    // Minimal share (the legacy opened a full modal). Copies a link + toasts.
    const showShareModal = (type, id, title) => {
        const url = location.origin + '/rhythm';
        if (navigator.clipboard) navigator.clipboard.writeText(url).catch(() => {});
        showToast('Link copied: ' + (title || url));
    };

    // The Cache API (`caches`) only exists in a secure context (HTTPS or
    // localhost). Served over plain HTTP (e.g. http://music.local) it is
    // absent, so the engine's offline lookups (`caches.match/open`) would throw
    // a ReferenceError and abort game prep. Fall back to a no-op CacheStorage so
    // those lookups simply miss and the engine downloads from the network.
    const caches = window.caches || {
        match: async () => undefined,
        open: async () => ({
            keys: async () => [],
            match: async () => undefined,
            put: async () => {},
            add: async () => {},
            addAll: async () => {},
            delete: async () => false,
        }),
        has: async () => false,
        delete: async () => false,
        keys: async () => [],
    };

    // Minimal Bootstrap-compatible modal shim (Bootstrap CSS is loaded on the
    // rhythm page, but we avoid pulling Bootstrap's JS just for show/hide).
    const bootstrap = {
        Modal: {
            getOrCreateInstance: (el) => ({
                show() { if (el) { el.classList.add('show'); el.style.display = 'block'; el.removeAttribute('aria-hidden'); } },
                hide() { if (el) { el.classList.remove('show'); el.style.display = 'none'; el.setAttribute('aria-hidden', 'true'); } },
            }),
        },
        Offcanvas: { getInstance: () => null, getOrCreateInstance: () => ({ show() {}, hide() {} }) },
    };

    const withId = (tpl, id) => tpl.replace('/0', '/' + id);

    // Map a legacy `?action=...` URL to a Symfony route.
    function mapUrl(legacyUrl, options) {
        const qi = legacyUrl.indexOf('?');
        const p = new URLSearchParams(qi >= 0 ? legacyUrl.slice(qi + 1) : '');
        const U = RG.urls;
        switch (p.get('action')) {
            case 'get_songs': {
                const extra = [];
                if (p.get('page')) extra.push('page=' + p.get('page'));
                if (p.get('q')) extra.push('q=' + encodeURIComponent(p.get('q')));
                return extra.length ? U.songs + '?' + extra.join('&') : U.songs;
            }
            case 'get_rhythm_chart': return U.chart.replace('/0/DIFF', '/' + p.get('song_id') + '/' + p.get('difficulty'));
            case 'get_rhythm_levels': return withId(U.levels, p.get('song_id'));
            case 'get_all_rhythm_levels': return U.allLevels;
            case 'get_rhythm_leaderboard': return U.leaderboard + '?page=' + (p.get('page') || '1');
            case 'get_song_rhythm_leaderboard': return withId(U.songLeaderboard, p.get('song_id'));
            case 'get_user_rhythm_scores': {
                let u = withId(U.userScores, p.get('target_user_id') || '0');
                const extra = [];
                if (p.get('all')) extra.push('all=' + p.get('all'));
                if (p.get('page')) extra.push('page=' + p.get('page'));
                return extra.length ? u + '?' + extra.join('&') : u;
            }
            case 'save_rhythm_score': return U.score;
            case 'toggle_rhythm_favorite': {
                let id = '0';
                try { id = String(JSON.parse(options && options.body).song_id); } catch (e) {}
                return withId(U.favoriteToggle, id);
            }
            case 'get_rhythm_favorites': return U.favorites;
            case 'get_rhythm_favorite_songs': return U.favoriteSongs;
            default: return legacyUrl; // out-of-scope endpoint (trimmed UI): 404s silently.
        }
    }

    // Drop-in replacement for the legacy `fetchData(url, opts, silent)` helper:
    // returns parsed JSON, or null on any error.
    async function fetchData(legacyUrl, options = {}, _silent = false) {
        const opts = { ...options, headers: { ...(options.headers || {}) } };
        if ((opts.method || 'GET').toUpperCase() === 'POST') {
            opts.headers['X-CSRF-TOKEN'] = RG.csrf;
        }
        try {
            const res = await window.fetch(mapUrl(legacyUrl, options), opts);
            if (!res.ok) return null;
            const ct = res.headers.get('Content-Type') || '';
            return ct.includes('application/json') ? await res.json() : null;
        } catch (e) {
            return null;
        }
    }

        class RhythmGameEngine {
          constructor() {
            this.LANES = 4;
            this.JUDGMENT = {
              PERFECT: 0.045,
              GREAT: 0.08,
              GOOD: 0.125,
              BAD: 0.17,
            };
    
            // Load saved settings and keybindings from local storage cache
            this.KEY_CODES = JSON.parse(localStorage.getItem("rg_key_codes")) || [
              "KeyA",
              "KeyS",
              "KeyD",
              "KeyF",
              "KeyG",
              "KeyH",
              "KeyJ",
              "KeyK",
              "KeyL",
              "Semicolon",
            ];
            this.KEY_LABELS = JSON.parse(localStorage.getItem("rg_key_labels")) || [
              "A",
              "S",
              "D",
              "F",
              "G",
              "H",
              "J",
              "K",
              "L",
              ";",
            ];
            this.calibrationOffsetMs =
              localStorage.getItem("rg_cal_offset") !== null
                ? parseInt(localStorage.getItem("rg_cal_offset"))
                : 0;
            this.userTickSpeed =
              localStorage.getItem("rg_tick_speed") !== null
                ? parseFloat(localStorage.getItem("rg_tick_speed"))
                : 1.0;
            this.sfxVolume =
              localStorage.getItem("rg_sfx_volume") !== null
                ? parseFloat(localStorage.getItem("rg_sfx_volume"))
                : 0.3;
    
            this.activeCodes = [];
            this.activeLabels = [];
            this.listeningLane = null;
    
            this.songs = [];
            this.filteredSongs = [];
            this.favFilteredSongs = [];
            this.artists = [];
            this.filteredArtists = [];
            this.artistDetailSongs = [];
            this.artistDetailFilteredSongs = [];
    
            this.songsPage = 1;
            this.favsPage = 1;
            this.artistsPage = 1;
            this.artistSongsPage = 1;
            this.artistFavsPage = 1;
            this.leaderboardPage = 1;
    
            this.allSongsLoaded = false;
            this.allFavsLoaded = false;
            this.allArtistsLoaded = false;
            this.allArtistSongsLoaded = false;
            this.allArtistFavsLoaded = false;
            this.allLeaderboardLoaded = false;
    
            this.isLoadingSongs = false;
            this.isLoadingFavs = false;
            this.isLoadingArtists = false;
            this.isLoadingArtistSongs = false;
            this.isLoadingArtistFavs = false;
            this.isLoadingLeaderboard = false;
    
            this.activeArtistDetailName = "";
            this.activeArtistDetailId = null;
    
            this.selectedSong = null;
            this.selectedDiff = "easy";
            this.currentSortMethod = "random";
            this.currentFavSortMethod = "manual_order";
    
            this.audioCtx = null;
            this.sfxCtx = null;
            this.audioBuffer = null;
            this.audioPlayback = null;
            this.chartNotes = [];
            this.isPlaying = false;
            this.activeKeysHeld = Array(10).fill(false);
            this.particles = [];
            this.laneApproachSpeed = 1.3;
            this.rgSongCache = {};
            this.curHp = 100;
            this.accuracy = 100.0;
            this.isCalibrating = false;
            this.calNotes = [];
            this.calCanvas = null;
            this.calCtx = null;
            this.calAnimId = null;
    
            this.curScore = 0;
            this.curCombo = 0;
            this.maxCombo = 0;
            this.stats = {
              perfect: 0,
              great: 0,
              good: 0,
              bad: 0,
              miss: 0,
            };
            this.hitDeltas = [];
            this.isAutoplay = false;
    
            // Performance Optimizations
            this.lastHUD = {
              score: -1,
              combo: -1,
              hp: -1,
              rank: null,
              acc: null,
            };
            this.noteStartIndex = 0;
            this.bgCanvas = document.createElement("canvas");
            this.bgCtx = this.bgCanvas.getContext("2d");
            this.MAX_PARTICLES = 100;
            this.particles = Array.from(
              {
                length: this.MAX_PARTICLES,
              },
              () => ({
                active: false,
              }),
            );
            this.pIdx = 0;
    
            this.feedbackTxt = "";
            this.feedbackCol = "#fff";
            this.feedbackTimer = 0;
            this.feedbackScale = 1;
    
            this.touchStartData = {};
    
            this.handleKeyDown = this.onKeyDown.bind(this);
            this.handleKeyUp = this.onKeyUp.bind(this);
            this.handleResize = this.resizeCanvas.bind(this);
            this.boundGameLoop = this.gameLoop.bind(this);
            this.boundCalLoop = this.runCalibrationLoop.bind(this);
    
            this.smoothTime = 0;
            this.lastReportedTime = 0;
            this.lastTimeUpdate = performance.now();
          }
    
          destroy() {
            // 1. Remove active global window listeners
            window.removeEventListener("keydown", this.handleKeyDown);
            window.removeEventListener("keyup", this.handleKeyUp);
            window.removeEventListener("resize", this.handleResize);
    
            if (this.startGameTimeout) clearTimeout(this.startGameTimeout);
    
            if (this.autoplayWakeLocked) {
              if (typeof noSleep !== "undefined") noSleep.disable();
              this.autoplayWakeLocked = false;
            }
    
            // 2. Shut down active audio playback safely
            if (this.audioPlayback) {
              this.audioPlayback.pause();
              this.audioPlayback.src = "";
              this.audioPlayback = null;
            }
    
            // 3. Kill background gameplay and calibration loops
            this.isPlaying = false;
            this.isCalibrating = false;
            if (this.calAnimId) {
              cancelAnimationFrame(this.calAnimId);
            }
    
            // 4. Release local calibration touch bindings
            const area = document.getElementById("rg-cal-test-area");
            if (area) {
              if (this.boundCalTouchStart)
                area.removeEventListener("touchstart", this.boundCalTouchStart);
              if (this.boundCalMouseDown)
              area.removeEventListener("mousedown", this.boundCalMouseDown);
            }

            // 5. Hide lingering UI dialogs to prevent them from showing after a reload or navigation
            const pauseDialog = document.getElementById("rg-dialog-pause");
            if (pauseDialog) pauseDialog.classList.add("rg-hidden");
            const playDialog = document.getElementById("rg-dialog-play");
            if (playDialog) playDialog.classList.add("rg-hidden");
            const chartDialog = document.getElementById("rg-dialog-chart-modal");
            if (chartDialog) chartDialog.classList.add("rg-hidden");

            // 6. Clean up main reference memory
            localRhythmGame = null;
          }
    
          initDOM() {
            this.screens = {
              hub: document.getElementById("rg-screen-hub"),
              loading: document.getElementById("rg-screen-loading"),
              game: document.getElementById("rg-screen-game"),
              result: document.getElementById("rg-screen-result"),
            };
            this.tabs = {
              songs: document.getElementById("rg-tab-songs"),
              offline: document.getElementById("rg-tab-offline"),
              artists: document.getElementById("rg-tab-artists"),
              artistDetail: document.getElementById("rg-tab-artist-detail"),
              favorites: document.getElementById("rg-tab-favorites"),
              leaderboard: document.getElementById("rg-tab-leaderboard"),
              settings: document.getElementById("rg-tab-settings"),
            };
            this.listSongsEl = document.getElementById("rg-list-songs");
            this.listFavsEl = document.getElementById("rg-list-favs");
            this.listOfflineEl = document.getElementById("rg-list-offline");
            this.allOfflineLoaded = false;
            this.isLoadingOffline = false;
            this.offlinePage = 1;
            this.allArtistFollowingLoaded = false;
            this.isLoadingArtistFollowing = false;
            this.artistFollowingPage = 1;
    
            setTimeout(() => {
              this.switchScreen("hub");
              if (!navigator.onLine) {
                const offlineTabBtn = document.querySelector(
                  '.rg-nav-item[data-target="offline"]',
                );
                if (offlineTabBtn) offlineTabBtn.click();
              } else {
                this.loadSongs();
              }
            }, 50);
    
            const navItems = document.querySelectorAll(".rg-nav-item");
            navItems.forEach((item) => {
              item.addEventListener("click", () => {
                navItems.forEach((n) => n.classList.remove("active"));
                item.classList.add("active");
                const target = item.getAttribute("data-target");
    
                if (target) {
                  // Cache active tab choice to survive page reloads
                  localStorage.setItem("rg_active_tab", target);
    
                  Object.values(this.tabs).forEach((t) => {
                    if (t) t.classList.add("rg-hidden");
                  });
                  if (this.tabs[target]) {
                    this.tabs[target].classList.remove("rg-hidden");
                  }
                  if (target === "artists") {
                    localStorage.removeItem("rg_active_artist");
                    this.loadArtists();
                  } else if (target === "favorites") {
                    localStorage.removeItem("rg_active_artist");
                    this.loadFavorites();
                  } else if (target === "offline") {
                    localStorage.removeItem("rg_active_artist");
                    this.loadOfflineSongs();
                  } else {
                    localStorage.removeItem("rg_active_artist");
                  }
                }
              });
            });
    
            const myProfileBtn = document.getElementById("rg-nav-my-profile");
            if (myProfileBtn) {
              myProfileBtn.addEventListener("click", () => {
                if (
                  typeof currentUser !== "undefined" &&
                  currentUser &&
                  currentUser.artist
                ) {
                  this.openArtistDetail(currentUser.artist);
                } else {
                  showToast("Please log in to view your profile.", "error");
                }
              });
            }
    
            // Artists Search & Sort (Null-safe)
            let searchArtistDebounce = null;
            const rgSearchArtists = document.getElementById("rg-search-artists");
            if (rgSearchArtists) {
              rgSearchArtists.addEventListener("input", (e) => {
                clearTimeout(searchArtistDebounce);
                searchArtistDebounce = setTimeout(() => {
                  this.loadArtists();
                }, 300);
              });
            }
            const rgSortArtists = document.getElementById("rg-sort-artists");
            if (rgSortArtists) {
              rgSortArtists.addEventListener("change", (e) => {
                this.loadArtists();
              });
            }
    
            // Offline Search & Sort
            let searchOfflineDebounce = null;
            const rgSearchOffline = document.getElementById("rg-search-offline");
            if (rgSearchOffline) {
              rgSearchOffline.addEventListener("input", (e) => {
                clearTimeout(searchOfflineDebounce);
                searchOfflineDebounce = setTimeout(() => {
                  this.loadOfflineSongs();
                }, 300);
              });
            }
            const rgSortOffline = document.getElementById("rg-sort-offline");
            if (rgSortOffline) {
              rgSortOffline.addEventListener("change", (e) => {
                this.loadOfflineSongs();
              });
            }
            if (this.listOfflineEl) {
              this.listOfflineEl.addEventListener("scroll", () => {
                if (
                  this.listOfflineEl.scrollTop + this.listOfflineEl.clientHeight >=
                  this.listOfflineEl.scrollHeight - 50
                ) {
                  if (!this.isLoadingOffline && !this.allOfflineLoaded) {
                    this.offlinePage++;
                    this.loadOfflineSongs(true);
                  }
                }
              });
            }
    
            // Chart Viewer logic
            const btnViewChart = document.getElementById("rg-btn-view-chart");
            const chartModal = document.getElementById("rg-dialog-chart-modal");
            const chartContent = document.getElementById("rg-chart-modal-content");
            const btnCloseChart = document.getElementById("rg-btn-close-chart");
            const btnDlChart = document.getElementById("rg-btn-dl-chart");
    
            if (btnViewChart && chartModal && chartContent) {
              btnViewChart.addEventListener("click", async () => {
                if (!this.selectedSong) return;
    
                chartModal.classList.remove("rg-hidden");
                chartContent.innerHTML =
                  '<div class="spinner-border text-danger mt-4" style="width: 2rem; height: 2rem; border-width: 0.25em;"></div><div class="text-secondary mt-2 small fw-bold text-uppercase">Compiling map...</div>';
    
                try {
                  const dbChart = await fetchData(
                    `?action=get_rhythm_chart&song_id=${this.selectedSong.id}&difficulty=${this.selectedDiff}`,
                    {},
                    true,
                  );
                  if (dbChart && dbChart.found && dbChart.notes) {
                    // Temporarily store state required for the local SVG generator method
                    const tempNotes = this.chartNotes;
                    const tempLanes = this.LANES;
                    const tempAudio = this.audioBuffer;
    
                    this.chartNotes = dbChart.notes;
                    this.LANES =
                      this.selectedDiff === "easy" || this.selectedDiff === "medium"
                        ? 4
                        : this.selectedDiff === "demon"
                          ? 10
                          : 6;
                    this.audioBuffer = {
                      duration: this.selectedSong.duration || 180,
                    };
    
                    const svgHtml = this.generateChartSVG();
                    chartContent.innerHTML = svgHtml;
    
                    // Restore actual live game state so we don't break playback
                    this.chartNotes = tempNotes;
                    this.LANES = tempLanes;
                    this.audioBuffer = tempAudio;
                  } else {
                    chartContent.innerHTML =
                      '<div class="text-danger mt-4 fw-bold">Failed to generate chart.</div>';
                  }
                } catch (err) {
                  chartContent.innerHTML =
                    '<div class="text-danger mt-4 fw-bold"><i class="bi bi-cloud-slash-fill me-1"></i> Offline or Network Error.</div>';
                }
              });
    
              if (btnCloseChart) {
                btnCloseChart.addEventListener("click", () =>
                  chartModal.classList.add("rg-hidden"),
                );
              }
    
              if (btnDlChart) {
                btnDlChart.addEventListener("click", () => {
                  const svgData = chartContent.innerHTML;
                  if (!svgData.includes("<svg"))
                    return showToast("No chart available to download.", "error");
                  const blob = new Blob([svgData], {
                    type: "image/svg+xml;charset=utf-8",
                  });
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement("a");
                  a.href = url;
                  a.download = `chart_${this.selectedSong.id}_${this.selectedDiff}.svg`;
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                });
              }
            }
    
            // Artist Songs Search & Sort (Null-safe)
            let searchArtistSongsDebounce = null;
            const rgSearchArtistSongs = document.getElementById(
              "rg-search-artist-songs",
            );
            if (rgSearchArtistSongs) {
              rgSearchArtistSongs.addEventListener("input", (e) => {
                clearTimeout(searchArtistSongsDebounce);
                searchArtistSongsDebounce = setTimeout(() => {
                  this.loadArtistSongs(this.activeArtistDetailName);
                }, 300);
              });
            }
            const rgSortArtistSongs = document.getElementById("rg-sort-artist-songs");
            if (rgSortArtistSongs) {
              rgSortArtistSongs.addEventListener("change", (e) => {
                this.loadArtistSongs(this.activeArtistDetailName);
              });
            }
    
            // Cancel Multi-Artist Dialog Click (Null-safe)
            const rgBtnArtistChoiceCancel = document.getElementById(
              "rg-btn-artist-choice-cancel",
            );
            if (rgBtnArtistChoiceCancel) {
              rgBtnArtistChoiceCancel.addEventListener("click", () => {
                const dialogChoice = document.getElementById(
                  "rg-dialog-artist-choice",
                );
                if (dialogChoice) dialogChoice.classList.add("rg-hidden");
              });
            }
    
            const keyBtns = document.querySelectorAll(".rg-key-btn");
            keyBtns.forEach((btn, idx) => {
              if (this.KEY_LABELS[idx]) btn.textContent = this.KEY_LABELS[idx];
              btn.addEventListener("click", () => {
                keyBtns.forEach((b) => b.classList.remove("listening"));
                this.listeningLane = parseInt(btn.getAttribute("data-lane"));
                btn.classList.add("listening");
              });
            });
    
            const offsetSlider = document.getElementById("rg-offset-slider");
            if (offsetSlider) {
              offsetSlider.value = this.calibrationOffsetMs;
              document.getElementById("rg-offset-display").textContent =
                `${this.calibrationOffsetMs >= 0 ? "+" : ""}${this.calibrationOffsetMs}ms`;
              offsetSlider.addEventListener("input", (e) => {
                this.calibrationOffsetMs = parseInt(e.target.value);
                document.getElementById("rg-offset-display").textContent =
                  `${this.calibrationOffsetMs >= 0 ? "+" : ""}${this.calibrationOffsetMs}ms`;
                localStorage.setItem("rg_cal_offset", this.calibrationOffsetMs);
              });
    
              document
                .getElementById("rg-offset-minus")
                ?.addEventListener("click", () => {
                  offsetSlider.value = Math.max(
                    parseInt(offsetSlider.min),
                    parseInt(offsetSlider.value) - parseInt(offsetSlider.step || 1),
                  );
                  offsetSlider.dispatchEvent(new Event("input"));
                });
              document
                .getElementById("rg-offset-plus")
                ?.addEventListener("click", () => {
                  offsetSlider.value = Math.min(
                    parseInt(offsetSlider.max),
                    parseInt(offsetSlider.value) + parseInt(offsetSlider.step || 1),
                  );
                  offsetSlider.dispatchEvent(new Event("input"));
                });
            }
    
            const speedSlider = document.getElementById("rg-speed-slider");
            if (speedSlider) {
              speedSlider.value = this.userTickSpeed;
              document.getElementById("rg-speed-display").textContent =
                `${this.userTickSpeed}x`;
              speedSlider.addEventListener("input", (e) => {
                this.userTickSpeed = parseFloat(e.target.value);
                this.userTickSpeed = Math.round(this.userTickSpeed * 10) / 10;
                document.getElementById("rg-speed-display").textContent =
                  `${this.userTickSpeed}x`;
                localStorage.setItem("rg_tick_speed", this.userTickSpeed);
              });
    
              document
                .getElementById("rg-speed-minus")
                ?.addEventListener("click", () => {
                  speedSlider.value = Math.max(
                    parseFloat(speedSlider.min),
                    parseFloat(speedSlider.value) -
                      parseFloat(speedSlider.step || 0.1),
                  );
                  speedSlider.dispatchEvent(new Event("input"));
                });
              document
                .getElementById("rg-speed-plus")
                ?.addEventListener("click", () => {
                  speedSlider.value = Math.min(
                    parseFloat(speedSlider.max),
                    parseFloat(speedSlider.value) +
                      parseFloat(speedSlider.step || 0.1),
                  );
                  speedSlider.dispatchEvent(new Event("input"));
                });
            }
    
            const sfxSlider = document.getElementById("rg-sfx-slider");
            if (sfxSlider) {
              sfxSlider.value = this.sfxVolume;
              document.getElementById("rg-sfx-display").textContent =
                Math.round(this.sfxVolume * 100) + "%";
              sfxSlider.addEventListener("input", (e) => {
                this.sfxVolume = parseFloat(e.target.value);
                this.sfxVolume = Math.round(this.sfxVolume * 100) / 100;
                document.getElementById("rg-sfx-display").textContent =
                  Math.round(this.sfxVolume * 100) + "%";
                localStorage.setItem("rg_sfx_volume", this.sfxVolume);
                if (this.sfxVolume > 0 && !this.sfxCtx) {
                  this.sfxCtx = new (
                    window.AudioContext || window.webkitAudioContext
                  )();
                }
                this.playHitSFX();
              });
    
              document
                .getElementById("rg-sfx-minus")
                ?.addEventListener("click", () => {
                  sfxSlider.value = Math.max(
                    parseFloat(sfxSlider.min),
                    parseFloat(sfxSlider.value) - parseFloat(sfxSlider.step || 0.05),
                  );
                  sfxSlider.dispatchEvent(new Event("input"));
                });
              document
                .getElementById("rg-sfx-plus")
                ?.addEventListener("click", () => {
                  sfxSlider.value = Math.min(
                    parseFloat(sfxSlider.max),
                    parseFloat(sfxSlider.value) + parseFloat(sfxSlider.step || 0.05),
                  );
                  sfxSlider.dispatchEvent(new Event("input"));
                });
            }
    
            const btnResetOptions = document.getElementById("rg-btn-reset-options");
            if (btnResetOptions) {
              btnResetOptions.addEventListener("click", () => {
                if (confirm("Reset gameplay options to defaults?")) {
                  this.calibrationOffsetMs = 0;
                  this.userTickSpeed = 1.0;
                  this.sfxVolume = 0.3;
    
                  localStorage.removeItem("rg_cal_offset");
                  localStorage.removeItem("rg_tick_speed");
                  localStorage.removeItem("rg_sfx_volume");
    
                  if (offsetSlider) {
                    offsetSlider.value = 0;
                    offsetSlider.dispatchEvent(new Event("input"));
                  }
                  if (speedSlider) {
                    speedSlider.value = 1.0;
                    speedSlider.dispatchEvent(new Event("input"));
                  }
                  if (sfxSlider) {
                    sfxSlider.value = 0.3;
                    sfxSlider.dispatchEvent(new Event("input"));
                  }
    
                  showToast("Options reset to default.", "success");
                }
              });
            }
    
            const calBtn = document.getElementById("rg-btn-cal-test");
            if (calBtn) {
              calBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                this.toggleCalibrationTest();
              });
            }
    
            let searchDebounceTimeout = null;
            document
              .getElementById("rg-search-songs")
              .addEventListener("input", (e) => {
                clearTimeout(searchDebounceTimeout);
                searchDebounceTimeout = setTimeout(() => {
                  this.loadSongs();
                }, 300);
              });
    
            let favSearchDebounceTimeout = null;
            document
              .getElementById("rg-search-favs")
              .addEventListener("input", (e) => {
                clearTimeout(favSearchDebounceTimeout);
                favSearchDebounceTimeout = setTimeout(() => {
                  this.loadFavorites();
                }, 300);
              });
    
            this.listSongsEl.addEventListener("scroll", () => {
              if (
                this.listSongsEl.scrollTop + this.listSongsEl.clientHeight >=
                this.listSongsEl.scrollHeight - 50
              ) {
                if (!this.isLoadingSongs && !this.allSongsLoaded) {
                  this.songsPage++;
                  this.loadSongs(true);
                }
              }
            });
    
            this.listFavsEl.addEventListener("scroll", () => {
              if (
                this.listFavsEl.scrollTop + this.listFavsEl.clientHeight >=
                this.listFavsEl.scrollHeight - 50
              ) {
                if (!this.isLoadingFavs && !this.allFavsLoaded) {
                  this.favsPage++;
                  this.loadFavorites(true);
                }
              }
            });
    
            const listArtistsEl = document.getElementById("rg-list-artists");
            if (listArtistsEl) {
              listArtistsEl.addEventListener("scroll", () => {
                if (
                  listArtistsEl.scrollTop + listArtistsEl.clientHeight >=
                  listArtistsEl.scrollHeight - 50
                ) {
                  if (!this.isLoadingArtists && !this.allArtistsLoaded) {
                    this.artistsPage++;
                    this.loadArtists(true);
                  }
                }
              });
            }
    
            const listArtistSongsEl = document.getElementById("rg-list-artist-songs");
            if (listArtistSongsEl) {
              listArtistSongsEl.addEventListener("scroll", () => {
                if (
                  listArtistSongsEl.scrollTop + listArtistSongsEl.clientHeight >=
                  listArtistSongsEl.scrollHeight - 50
                ) {
                  if (!this.isLoadingArtistSongs && !this.allArtistSongsLoaded) {
                    this.artistSongsPage++;
                    this.loadArtistSongs(this.activeArtistDetailName, true);
                  }
                }
              });
            }
    
            // Artist Favorites Search & Sort (Null-safe)
            let searchArtistFavsDebounce = null;
            const rgSearchArtistFavs = document.getElementById(
              "rg-search-artist-favs",
            );
            if (rgSearchArtistFavs) {
              rgSearchArtistFavs.addEventListener("input", (e) => {
                clearTimeout(searchArtistFavsDebounce);
                searchArtistFavsDebounce = setTimeout(() => {
                  this.loadArtistFavorites(this.activeArtistDetailId);
                }, 300);
              });
            }
            const rgSortArtistFavs = document.getElementById("rg-sort-artist-favs");
            if (rgSortArtistFavs) {
              rgSortArtistFavs.addEventListener("change", (e) => {
                this.loadArtistFavorites(this.activeArtistDetailId);
              });
            }
    
            // Artist Following Search & Sort (Null-safe)
            let searchArtistFollowingDebounce = null;
            const rgSearchArtistFollowing = document.getElementById(
              "rg-search-artist-following",
            );
            if (rgSearchArtistFollowing) {
              rgSearchArtistFollowing.addEventListener("input", (e) => {
                clearTimeout(searchArtistFollowingDebounce);
                searchArtistFollowingDebounce = setTimeout(() => {
                  this.loadArtistFollowing(this.activeArtistDetailId);
                }, 300);
              });
            }
            const rgSortArtistFollowing = document.getElementById(
              "rg-sort-artist-following",
            );
            if (rgSortArtistFollowing) {
              rgSortArtistFollowing.addEventListener("change", (e) => {
                this.loadArtistFollowing(this.activeArtistDetailId);
              });
            }
    
            const listArtistFavsEl = document.getElementById("rg-list-artist-favs");
            if (listArtistFavsEl) {
              listArtistFavsEl.addEventListener("scroll", () => {
                if (
                  listArtistFavsEl.scrollTop + listArtistFavsEl.clientHeight >=
                  listArtistFavsEl.scrollHeight - 50
                ) {
                  if (!this.isLoadingArtistFavs && !this.allArtistFavsLoaded) {
                    this.artistFavsPage++;
                    this.loadArtistFavorites(this.activeArtistDetailId, true);
                  }
                }
              });
            }
    
            const listArtistFollowingEl = document.getElementById(
              "rg-list-artist-following",
            );
            if (listArtistFollowingEl) {
              listArtistFollowingEl.addEventListener("scroll", () => {
                if (
                  listArtistFollowingEl.scrollTop +
                    listArtistFollowingEl.clientHeight >=
                  listArtistFollowingEl.scrollHeight - 50
                ) {
                  if (
                    !this.isLoadingArtistFollowing &&
                    !this.allArtistFollowingLoaded
                  ) {
                    this.artistFollowingPage++;
                    this.loadArtistFollowing(this.activeArtistDetailId, true);
                  }
                }
              });
            }
    
            const listLeaderboardEl = document.getElementById("rg-list-leaderboard");
            if (listLeaderboardEl) {
              listLeaderboardEl.addEventListener("scroll", () => {
                if (
                  listLeaderboardEl.scrollTop + listLeaderboardEl.clientHeight >=
                  listLeaderboardEl.scrollHeight - 50
                ) {
                  if (!this.isLoadingLeaderboard && !this.allLeaderboardLoaded) {
                    this.leaderboardPage++;
                    this.loadLeaderboard(true);
                  }
                }
              });
            }
    
            const diffBtns = document.querySelectorAll(".rg-diff-btn");
            diffBtns.forEach((btn) => {
              btn.addEventListener("click", () => {
                diffBtns.forEach((b) => b.classList.remove("active"));
                btn.classList.add("active");
                this.selectedDiff = btn.getAttribute("data-diff");
    
                // Show/hide landscape warning based on Demon selection
                const demonWarning = document.getElementById("rg-demon-warning");
                if (demonWarning) {
                  if (this.selectedDiff === "demon") {
                    demonWarning.style.maxHeight = "120px";
                    demonWarning.style.opacity = "1";
                    demonWarning.style.marginTop = "16px";
                    demonWarning.style.borderWidth = "1px";
                  } else {
                    demonWarning.style.maxHeight = "0";
                    demonWarning.style.opacity = "0";
                    demonWarning.style.marginTop = "0";
                    demonWarning.style.borderWidth = "0";
                  }
                }
              });
            });
    
            // Dialog Favorite Button Click Listener
            const dialogFavBtn = document.getElementById("rg-dialog-fav-btn");
            if (dialogFavBtn) {
              dialogFavBtn.addEventListener("click", async (e) => {
                e.stopPropagation();
                if (!this.selectedSong) return;
                const res = await fetchData("?action=toggle_rhythm_favorite", {
                  method: "POST",
                  headers: {
                    "Content-Type": "application/json",
                  },
                  body: JSON.stringify({
                    song_id: this.selectedSong.id,
                  }),
                });
                if (res) {
                  this.selectedSong.rg_favorite = res.is_favorite;
                  const isFav = res.is_favorite == 1;
                  const favIcon = document.getElementById("rg-dialog-fav-icon");
                  const favText = document.getElementById("rg-dialog-fav-text");
                  if (favIcon && favText) {
                    favIcon.className = isFav
                      ? "bi bi-heart-fill text-danger"
                      : "bi bi-heart";
                    favText.textContent = isFav ? "Unfavorite" : "Favorite";
                    dialogFavBtn.classList.toggle("text-danger", isFav);
                  }
                  // Synchronize state with standard song cards
                  const mainFavBtn = document.querySelector(
                    `.rg-list-item[data-song-id="${this.selectedSong.id}"] .rg-fav-btn`,
                  );
                  if (mainFavBtn) {
                    mainFavBtn.classList.toggle("text-danger", isFav);
                    mainFavBtn.classList.toggle("text-secondary", !isFav);
                    mainFavBtn.innerHTML = `<i class="bi ${isFav ? "bi-heart-fill" : "bi-heart"} fs-5"></i>`;
                  }
                }
              });
            }
    
            // Dialog Share Button Click Listener
            const dialogShareBtn = document.getElementById("rg-dialog-share-btn");
            if (dialogShareBtn) {
              dialogShareBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                if (!this.selectedSong) return;
                if (typeof showShareModal === "function") {
                  showShareModal(
                    "game",
                    this.selectedSong.id,
                    this.selectedSong.title,
                  );
                }
              });
            }
    
            document
              .getElementById("rg-sort-songs")
              .addEventListener("change", (e) => {
                this.loadSongs();
              });
    
            document
              .getElementById("rg-sort-favs")
              .addEventListener("change", (e) => {
                this.loadFavorites();
              });
    
            document
              .getElementById("rg-btn-dialog-cancel")
              .addEventListener("click", () =>
                document.getElementById("rg-dialog-play").classList.add("rg-hidden"),
              );
            document
              .getElementById("rg-btn-dialog-play")
              .addEventListener("click", () => {
                // Block mobile portrait users from starting a Demon mode session
                if (
                  this.selectedDiff === "demon" &&
                  window.innerWidth < window.innerHeight
                ) {
                  showToast(
                    "Demon difficulty requires Landscape orientation or Desktop screen width!",
                    "error",
                  );
                  return;
                }
    
                document.getElementById("rg-dialog-play").classList.add("rg-hidden");
                const apToggle = document.getElementById("rg-autoplay-toggle");
                this.isAutoplay = apToggle ? apToggle.checked : false;
    
                if (this.isAutoplay && typeof noSleep !== "undefined") {
                  try {
                    noSleep.enable();
                    this.autoplayWakeLocked = true;
                  } catch (err) {
                    console.warn("Autoplay Wake Lock failed:", err);
                  }
                }
    
                this.prepGame(this.selectedSong, this.selectedDiff);
              });
    
            const pauseDialog = document.getElementById("rg-dialog-pause");
            document
              .getElementById("rg-btn-quit-game")
              .addEventListener("click", () => {
                this.pauseGame();
              });
            document.getElementById("rg-btn-resume").addEventListener("click", () => {
              pauseDialog.classList.add("rg-hidden");
    
              let countdownEl = document.getElementById("rg-resume-countdown");
              if (!countdownEl) {
                countdownEl = document.createElement("div");
                countdownEl.id = "rg-resume-countdown";
                countdownEl.style.cssText =
                  "position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-family: sans-serif; font-weight: 900; font-size: 6rem; color: var(--rg-primary); z-index: 100; pointer-events: none; text-shadow: 0 4px 12px rgba(0,0,0,0.8); transition: transform 0.1s ease-out, opacity 0.15s; opacity: 0;";
                const gameScreen = document.getElementById("rg-screen-game");
                if (gameScreen) gameScreen.appendChild(countdownEl);
              }
    
              const runCountdown = (count) => {
                if (count > 0) {
                  countdownEl.textContent = count;
                  countdownEl.style.transform = "translate(-50%, -50%) scale(1.3)";
                  countdownEl.style.opacity = "1";
                  setTimeout(() => {
                    countdownEl.style.transform = "translate(-50%, -50%) scale(1)";
                  }, 100);
                  setTimeout(() => {
                    runCountdown(count - 1);
                  }, 1000);
                } else {
                  countdownEl.textContent = "GO!";
                  setTimeout(() => {
                    countdownEl.style.opacity = "0";
                    setTimeout(() => {
                      countdownEl.textContent = "";
                    }, 150);
                  }, 500);
    
                  // Resume game
                  this.isPlaying = true;
                  this.lastReportedTime = 0;
                  this.lastTimeUpdate = performance.now();
                  if (this.audioPlayback) this.audioPlayback.play();
                  requestAnimationFrame(this.boundGameLoop);
                }
              };
    
              runCountdown(3);
            });
            document
              .getElementById("rg-btn-retry-pause")
              .addEventListener("click", () => {
                pauseDialog.classList.add("rg-hidden");
                this.switchScreen("loading");
                this.prepGame(this.selectedSong, this.selectedDiff);
              });
            document.getElementById("rg-btn-quit").addEventListener("click", () => {
              pauseDialog.classList.add("rg-hidden");
              this.isProgrammaticExit = true;
              history.back();
            });
    
            document
              .getElementById("rg-btn-result-back")
              .addEventListener("click", () => {
                this.isProgrammaticExit = true;
                history.back();
              });
    
            // Auto-pause game when browser is minimized, tab is switched, or screen turns off
            document.addEventListener("visibilitychange", () => {
              if (document.visibilityState !== "visible" && this.isPlaying) {
                this.pauseGame();
              }
            });
            window.addEventListener("blur", () => {
              if (this.isPlaying) {
                this.pauseGame();
              }
            });
            document
              .getElementById("rg-btn-result-retry")
              .addEventListener("click", () => {
                this.switchScreen("loading");
                this.prepGame(this.selectedSong, this.selectedDiff);
              });
            document
              .getElementById("rg-btn-result-share")
              .addEventListener("click", () => {
                const textPayload = `I just scored ${parseInt(this.curScore).toLocaleString()} (${this.maxCombo}x Combo) on ${this.selectedSong.title} [${this.selectedDiff.toUpperCase()}] in PHP Music Rhythm Game!`;
                const coverUrl = `/cover/${this.selectedSong.id}`;
                if (typeof showShareModal === "function") {
                  showShareModal(
                    "game",
                    this.selectedSong.id,
                    this.selectedSong.title,
                    "",
                    "",
                    coverUrl,
                    "",
                    textPayload,
                  );
                }
              });
    
            const touchLayer = document.getElementById("rg-touch-layer");
    
            const getLaneFromX = (clientX) => {
              const rect = this.canvas.getBoundingClientRect();
              const w = rect.width;
              const isPortrait = rect.height > rect.width;
    
              let botW = this.botW;
              if (!botW) {
                if (this.LANES === 10) {
                  botW = isPortrait ? w * 0.73 : w * 0.75;
                } else if (this.LANES === 6) {
                  botW = isPortrait ? w * 0.72 : w * 0.68;
                } else {
                  botW = isPortrait ? w * 0.68 : w * 0.55;
                }
              }
    
              const sBotW = botW / this.LANES;
              const startX = w / 2 - botW / 2;
    
              const x = clientX - rect.left;
              if (x >= startX && x <= startX + botW) {
                return Math.floor((x - startX) / sBotW);
              }
              if (x < startX) return 0;
              if (x > startX + botW) return this.LANES - 1;
              return -1;
            };
    
            const handleTouchStart = (e) => {
              if (!this.isPlaying || this.isAutoplay) return;
              for (let i = 0; i < e.changedTouches.length; i++) {
                const t = e.changedTouches[i];
                this.touchStartData[t.identifier] = {
                  y: t.clientY,
                };
                const lane = getLaneFromX(t.clientX);
                if (lane >= 0 && lane < this.LANES) {
                  this.activeKeysHeld[lane] = true;
                  this.processHit(lane, true, false);
                }
              }
              e.preventDefault();
            };
    
            const handleTouchMove = (e) => {
              if (!this.isPlaying || this.isAutoplay) return;
              for (let i = 0; i < e.changedTouches.length; i++) {
                const t = e.changedTouches[i];
                const start = this.touchStartData[t.identifier];
                if (start && start.y - t.clientY > 15) {
                  const lane = getLaneFromX(t.clientX);
                  if (lane >= 0 && lane < this.LANES) {
                    this.processHit(lane, true, true);
                  }
                  delete this.touchStartData[t.identifier];
                }
              }
            };
    
            const handleTouchEnd = (e) => {
              if (!this.isPlaying || this.isAutoplay) return;
              for (let i = 0; i < e.changedTouches.length; i++) {
                const t = e.changedTouches[i];
                const lane = getLaneFromX(t.clientX);
                if (lane >= 0 && lane < this.LANES) {
                  this.activeKeysHeld[lane] = false;
                }
                delete this.touchStartData[t.identifier];
              }
              e.preventDefault();
            };
    
            const handleMouseDown = (e) => {
              if (!this.isPlaying || this.isAutoplay) return;
              const lane = getLaneFromX(e.clientX);
              if (lane >= 0 && lane < this.LANES) {
                this.activeKeysHeld[lane] = true;
                this.processHit(lane, true, false);
              }
            };
    
            const handleMouseUp = (e) => {
              if (!this.isPlaying || this.isAutoplay) return;
              for (let i = 0; i < this.LANES; i++) {
                this.activeKeysHeld[i] = false;
              }
            };
    
            touchLayer.addEventListener("touchstart", handleTouchStart, {
              passive: false,
            });
            touchLayer.addEventListener("touchmove", handleTouchMove, {
              passive: false,
            });
            touchLayer.addEventListener("touchend", handleTouchEnd, {
              passive: false,
            });
            touchLayer.addEventListener("mousedown", handleMouseDown);
            touchLayer.addEventListener("mouseup", handleMouseUp);
            touchLayer.addEventListener("mouseleave", handleMouseUp);
    
            const setupArtistTabs = () => {
              const tabs = ["tracks", "history", "favs", "following", "playlists"];
              tabs.forEach((tab) => {
                const btn = document.getElementById(`rg-art-tab-${tab}`);
                if (btn) {
                  btn.addEventListener("click", () => {
                    tabs.forEach((t) => {
                      const b = document.getElementById(`rg-art-tab-${t}`);
                      const p = document.getElementById(`rg-art-pane-${t}`);
                      if (b) b.classList.remove("active");
                      if (p) p.classList.replace("d-flex", "d-none");
                    });
                    btn.classList.add("active");
                    const pane = document.getElementById(`rg-art-pane-${tab}`);
                    if (pane) pane.classList.replace("d-none", "d-flex");
                  });
                }
              });
            };
            setupArtistTabs();
    
            window.addEventListener("keydown", this.handleKeyDown);
            window.addEventListener("keyup", this.handleKeyUp);
            window.addEventListener("resize", this.handleResize);
          }
    
          async loadSongs(append = false) {
            if (!append) {
              this.songsPage = 1;
              if (this.listSongsEl) {
                this.listSongsEl.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allSongsLoaded = false;
            }
            if (this.allSongsLoaded) return;
            this.isLoadingSongs = true;
    
            if (!navigator.onLine) {
              if (!append && this.listSongsEl) {
                this.listSongsEl.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100"><i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i><h5 class="fw-bold text-white">You are offline</h5><p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p><button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button></div>`;
              }
              this.allSongsLoaded = true;
              this.isLoadingSongs = false;
              return;
            }
    
            const sort = document.getElementById("rg-sort-songs").value;
            const q = document.getElementById("rg-search-songs").value.trim();
    
            let url = `?action=get_songs&page=${this.songsPage}&sort=${sort}&rhythm_game=1`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              // Cache favorites globally only once during the active game session to eliminate HTTP loops
              if (!window.rhythmFavsSet) {
                const favData =
                  (await fetchData("?action=get_rhythm_favorites", {}, true)) || [];
                window.rhythmFavsSet = new Set(
                  Array.isArray(favData) ? favData.map((id) => parseInt(id)) : [],
                );
              }
    
              const res = await fetchData(url, {}, true);
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && this.listSongsEl)
                  this.listSongsEl.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">No songs found.</div>';
                this.allSongsLoaded = true;
              } else {
                if (!append && this.listSongsEl) {
                  this.listSongsEl.innerHTML = "";
    
                  // Map retrieved levels directly into client cache memory
                  res.forEach((song) => {
                    if (song.levels) {
                      if (!window.rhythmLevelsMap)
                        window.rhythmLevelsMap = {
                          levels: {},
                        };
                      window.rhythmLevelsMap.levels[song.id] = song.levels;
                    }
                  });
    
                  if (
                    (!window.rhythmLevelsMap ||
                      !window.rhythmLevelsMap.levels ||
                      Object.keys(window.rhythmLevelsMap.levels).length === 0) &&
                    !q
                  ) {
                    const isAdmin =
                      currentUser &&
                      (currentUser.is_admin == 1 ||
                        currentUser.status === "super_admin");
                    this.listSongsEl.insertAdjacentHTML(
                      "beforeend",
                      `
                            <div style="background: rgba(255, 59, 48, 0.1); border: 1px solid var(--rg-primary); border-radius: 16px; padding: 20px; margin-bottom: 16px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                              <i class="bi bi-exclamation-triangle-fill text-danger d-block mb-2" style="font-size: 2.5rem;"></i>
                              <h6 class="text-white fw-bold text-uppercase mb-2" style="letter-spacing: 1px;">No Charts Generated</h6>
                              <p class="text-secondary small mb-3">The database currently has no pre-compiled note charts. Songs will generate on-the-fly when clicked, which may cause a slight initial delay.</p>
                              ${isAdmin ? `<button class="btn btn-danger rounded-pill fw-bold px-4 py-2 shadow-sm" onclick="document.getElementById('nav-chart-scan').click();"><i class="bi bi-open-chart-scan me-1"></i> Run Server Scanner Now</button>` : `<div class="badge bg-dark border border-secondary text-secondary p-2"><i class="bi bi-lock-fill me-1"></i> Contact Admin to Run Scanner</div>`}
                            </div>
                          `,
                    );
                  }
                }
                res.forEach((song) => {
                  song.rg_favorite = window.rhythmFavsSet.has(parseInt(song.id))
                    ? 1
                    : 0;
                  if (this.listSongsEl)
                    this.listSongsEl.appendChild(this.createSongElement(song));
                });
                if (res.length < 25) this.allSongsLoaded = true;
              }
    
              if (!append) {
                this.loadFavorites();
                this.loadLeaderboard();
                const savedTab = localStorage.getItem("rg_active_tab") || "songs";
                const savedArtist = localStorage.getItem("rg_active_artist");
                if (savedTab === "artistDetail" && savedArtist) {
                  this.openArtistDetail(savedArtist);
                } else {
                  const activeNavItem = document.querySelector(
                    `.rg-nav-item[data-target="${savedTab}"]`,
                  );
                  if (activeNavItem) {
                    document
                      .querySelectorAll(".rg-nav-item")
                      .forEach((n) => n.classList.remove("active"));
                    activeNavItem.classList.add("active");
                    Object.values(this.tabs).forEach((t) => {
                      if (t) t.classList.add("rg-hidden");
                    });
                    if (this.tabs[savedTab])
                      this.tabs[savedTab].classList.remove("rg-hidden");
    
                    if (savedTab === "artists") {
                      this.loadArtists();
                    } else if (savedTab === "favorites") {
                      this.loadFavorites();
                    } else if (savedTab === "offline") {
                      this.loadOfflineSongs();
                    }
                  }
                }
                if (typeof currentView !== "undefined" && currentView.artist_name) {
                  this.openArtistDetail(currentView.artist_name);
                  delete currentView.artist_name;
                  if (currentView.artist_id) delete currentView.artist_id;
                } else if (typeof currentView !== "undefined" && currentView.param) {
                  const targetId = parseInt(currentView.param);
                  try {
                    const fetchedData = await fetchData(
                      `?action=get_song_data&id=${targetId}`,
                      {},
                      true,
                    );
                    if (fetchedData && fetchedData.id) {
                      fetchedData.rg_favorite =
                        window.rhythmFavsSet && window.rhythmFavsSet.has(targetId)
                          ? 1
                          : 0;
                      this.openPlayDialog(fetchedData);
                      currentView.param = "";
                    }
                  } catch (e) {}
                }
              }
            } catch (e) {
              console.error("Game Engine Failed to Load Tracks:", e);
              if (!append && this.listSongsEl) {
                this.listSongsEl.innerHTML = !navigator.onLine
                  ? `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100">
                          <i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i>
                          <h5 class="fw-bold text-white">You are offline</h5>
                          <p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p>
                          <button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button>
                        </div>`
                  : '<div style="text-align:center; padding: 24px; color: #ffb4ab;">Error establishing database connection.</div>';
              }
            }
            this.isLoadingSongs = false;
          }
    
          async loadFavorites(append = false) {
            if (!append) {
              this.favsPage = 1;
              if (this.listFavsEl) {
                this.listFavsEl.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allFavsLoaded = false;
            }
            if (this.allFavsLoaded) return;
            this.isLoadingFavs = true;
    
            if (!navigator.onLine) {
              if (!append && this.listFavsEl) {
                this.listFavsEl.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100"><i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i><h5 class="fw-bold text-white">You are offline</h5><p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p><button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button></div>`;
              }
              this.allFavsLoaded = true;
              this.isLoadingFavs = false;
              return;
            }
    
            const sort = document.getElementById("rg-sort-favs").value;
            const q = document.getElementById("rg-search-favs").value.trim();
    
            let url = `?action=get_rhythm_favorite_songs&page=${this.favsPage}&sort=${sort}`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              const res = await fetchData(url, {}, true);
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && this.listFavsEl)
                  this.listFavsEl.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">No favorite songs yet. Add some!</div>';
                this.allFavsLoaded = true;
              } else {
                if (!append && this.listFavsEl) this.listFavsEl.innerHTML = "";
                res.forEach((song) => {
                  song.rg_favorite = 1;
                  if (this.listFavsEl)
                    this.listFavsEl.appendChild(this.createSongElement(song));
                });
                if (res.length < 25) this.allFavsLoaded = true;
              }
            } catch (e) {
              console.error(e);
              if (!append && this.listFavsEl && !navigator.onLine) {
                this.listFavsEl.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100">
                          <i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i>
                          <h5 class="fw-bold text-white">You are offline</h5>
                          <p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p>
                          <button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button>
                        </div>`;
              }
            }
            this.isLoadingFavs = false;
          }
    
          async loadLeaderboard(append = false) {
            if (!append) {
              this.leaderboardPage = 1;
              const container = document.getElementById("rg-list-leaderboard");
              if (container) {
                container.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allLeaderboardLoaded = false;
            }
            if (this.allLeaderboardLoaded) return;
            this.isLoadingLeaderboard = true;
    
            if (!navigator.onLine) {
              const container = document.getElementById("rg-list-leaderboard");
              if (!append && container) {
                container.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100"><i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i><h5 class="fw-bold text-white">You are offline</h5><p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p><button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button></div>`;
              }
              this.allLeaderboardLoaded = true;
              this.isLoadingLeaderboard = false;
              return;
            }
    
            try {
              const data = await fetchData(
                `?action=get_rhythm_leaderboard&page=${this.leaderboardPage}`,
                {},
                true,
              );
              const container = document.getElementById("rg-list-leaderboard");
    
              if (!Array.isArray(data) || data.length === 0) {
                if (!append && container)
                  container.innerHTML =
                    '<div style="text-align:center; padding:24px; color:#aaa;">No scores recorded yet. Be the first to play!</div>';
                this.allLeaderboardLoaded = true;
              } else {
                if (!append && container) container.innerHTML = "";
                const html = data
                  .map((entry, idx) => {
                    const globalIdx = (this.leaderboardPage - 1) * 25 + idx + 1;
                    const jud = this.getRankFromStats(
                      entry.total_perfect || 0,
                      entry.total_great || 0,
                      entry.total_good || 0,
                      entry.total_bad || 0,
                      entry.total_miss || 0,
                    );
                    const cacheBuster = Math.floor(Date.now() / 60000);
                    const totalHits =
                      parseInt(entry.total_perfect || 0) +
                      parseInt(entry.total_great || 0) +
                      parseInt(entry.total_good || 0) +
                      parseInt(entry.total_bad || 0) +
                      parseInt(entry.total_miss || 0);
                    const suspicious =
                      jud.acc === "100.00" && totalHits > 50
                        ? '<span class="badge bg-danger ms-2" style="font-size: 0.6rem;" title="Suspicious Activity"><i class="bi bi-exclamation-triangle-fill"></i> SUS</span>'
                        : "";
                    return `
                          <div class="rg-list-item rank-row" data-userid="${entry.user_id}" data-player-name="${encodeURIComponent(entry.player_name)}" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                            <div class="rg-list-item-info" style="min-width: 0; flex: 1;">
                              <div style="width: 24px; font-weight: 900; color: var(--rg-primary); text-align: center;">${globalIdx}</div>
                              <img src="?action=get_profile_picture&id=${entry.user_id}&v=${cacheBuster}" class="rg-cover-img" style="border-radius: 50%;">
                              <div style="min-width: 0; flex: 1; margin-right: 12px;">
                                <div class="rg-list-item-title text-truncate">${escapeHTML(entry.player_name)}${suspicious}</div>
                                <div class="rg-list-item-sub text-truncate" style="color: var(--rg-primary); font-size: 0.8rem; font-weight: 700;">
                                  Total Score: ${parseInt(entry.total_score).toLocaleString()}
                                  <span style="color:#aaa; font-size:0.75rem; margin-left:8px;">(${entry.total_plays} Plays)</span>
                                </div>
                              </div>
                            </div>
                            <div class="d-flex align-items-center gap-2" style="flex-shrink: 0;">
                              <span class="badge d-flex align-items-center justify-content-center" style="font-family: monospace; font-size: 0.75rem; font-weight: 900; background-color: ${jud.color}; color: #000; height: 26px; min-width: 32px; border-radius: 6px; box-shadow: 0 2px 8px ${jud.color}40;" title="AVERAGE RANK">${jud.rank}</span>
                            </div>
                          </div>
                        `;
                  })
                  .join("");
    
                if (container) {
                  container.insertAdjacentHTML("beforeend", html);
                  const rows = container.querySelectorAll(".rank-row:not(.bound)");
                  rows.forEach((row) => {
                    row.classList.add("bound");
                    row.addEventListener("click", () => {
                      this.openPlayerStats(
                        row.dataset.userid,
                        decodeURIComponent(row.dataset.playerName),
                        1,
                      );
                    });
                  });
                }
                if (data.length < 25) this.allLeaderboardLoaded = true;
              }
            } catch (err) {
              console.error("Failed to load Leaderboard:", err);
              const container = document.getElementById("rg-list-leaderboard");
              if (!append && container && !navigator.onLine) {
                container.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100">
                          <i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i>
                          <h5 class="fw-bold text-white">You are offline</h5>
                          <p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p>
                          <button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button>
                        </div>`;
              }
            }
            this.isLoadingLeaderboard = false;
          }
    
          async loadOfflineSongs(append = false) {
            if (!append) {
              this.offlinePage = 1;
              if (this.listOfflineEl) {
                this.listOfflineEl.innerHTML = `
                      <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                        <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                      </div>`;
              }
              this.allOfflineLoaded = false;
            }
            if (this.allOfflineLoaded) return;
            this.isLoadingOffline = true;
    
            const sort = document.getElementById("rg-sort-offline").value;
            const q = document
              .getElementById("rg-search-offline")
              .value.trim()
              .toLowerCase();
    
            try {
              if (!window.rhythmLevelsMap) {
                const levelsRes = await fetchData(
                  "?action=get_all_rhythm_levels",
                  {},
                  true,
                );
                window.rhythmLevelsMap = levelsRes || {
                  levels: {},
                };
              }
              const favData =
                (await fetchData("?action=get_rhythm_favorites", {}, true)) || [];
              const favSet = new Set(
                Array.isArray(favData) ? favData.map((id) => parseInt(id)) : [],
              );
    
              let res = null;
    
              // Try to grab from the main app's global offline memory first
              if (
                window.offlineViewSongsData &&
                window.offlineViewSongsData.length > 0
              ) {
                res = [...window.offlineViewSongsData];
              } else {
                // Fallback: Fetch exactly what the Service Worker caches to guarantee a hit
                res = await fetchData(
                  `?action=get_offline_songs&sort=manual_order&all=1`,
                  {},
                  true,
                );
    
                // DEEP OFFLINE HARDWARE FALLBACK: Extract directly from binary cache if API is unreachable
                if ((!res || res.length === 0) && !navigator.onLine) {
                  try {
                    const cache = await caches.open("php-music-offline");
                    const keys = await cache.keys();
                    res = [];
                    for (let req of keys) {
                      if (req.url.includes("action=get_song_data")) {
                        const cachedRes = await cache.match(req);
                        if (cachedRes) {
                          const songData = await cachedRes.json();
                          if (songData && songData.id) res.push(songData);
                        }
                      }
                    }
                  } catch (e) {
                    console.error("Rhythm Game offline cache extraction failed", e);
                  }
                }
              }
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && this.listOfflineEl)
                  this.listOfflineEl.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">No offline tracks found.</div>';
                this.allOfflineLoaded = true;
              } else {
                // Apply Sorting Client-Side to support pure offline sorting without backend queries
                if (sort === "title_asc")
                  res.sort((a, b) => a.title.localeCompare(b.title));
                else if (sort === "artist_asc")
                  res.sort((a, b) => a.artist.localeCompare(b.artist));
                else if (sort === "added_newest") res.sort((a, b) => b.id - a.id);
                else if (sort === "trending")
                  res.sort((a, b) => (b.play_count || 0) - (a.play_count || 0));
                else if (sort === "random") res.sort(() => Math.random() - 0.5);
    
                // Apply Search Filter Client-Side
                if (q) {
                  res = res.filter(
                    (s) =>
                      (s.title && s.title.toLowerCase().includes(q)) ||
                      (s.artist && s.artist.toLowerCase().includes(q)),
                  );
                }
    
                // Apply Pagination Client-Side to maintain performance
                const startIndex = (this.offlinePage - 1) * 25;
                const chunk = res.slice(startIndex, startIndex + 25);
    
                if (!append && this.listOfflineEl) this.listOfflineEl.innerHTML = "";
    
                if (!append && chunk.length === 0 && this.listOfflineEl) {
                  this.listOfflineEl.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">No offline tracks matching search found.</div>';
                }
    
                chunk.forEach((song) => {
                  song.rg_favorite = favSet.has(parseInt(song.id)) ? 1 : 0;
                  if (this.listOfflineEl)
                    this.listOfflineEl.appendChild(this.createSongElement(song));
                });
    
                if (startIndex + 25 >= res.length) {
                  this.allOfflineLoaded = true;
                }
    
                if ("caches" in window) {
                  caches.open("php-music-offline").then((cache) => {
                    if (this.listOfflineEl) {
                      const addedItems = this.listOfflineEl.querySelectorAll(
                        ".rg-list-item:not(.rg-cache-checked)",
                      );
                      addedItems.forEach(async (item) => {
                        item.classList.add("rg-cache-checked");
                        const sid = item.dataset.songId;
                        if (!sid) return;
                        
                        let isCached = false;
                        try {
                          if (navigator.storage && navigator.storage.getDirectory) {
                            const root = await navigator.storage.getDirectory();
                            const dir = await root.getDirectoryHandle("offline_music_cache");
                            await dir.getFileHandle(`song_${sid}`);
                            isCached = true;
                          }
                        } catch(e) {}

                        if (!isCached) {
                          const req = await cache.match(
                            `/stream/${sid}`,
                            {
                              ignoreSearch: false,
                              ignoreVary: true,
                            },
                          );
                          if (req) isCached = true;
                        }
                        
                        if (!isCached) {
                          item.style.opacity = "0.85";
                          item.classList.add("offline-missing");
                          const infoCol = item.querySelector(
                            ".d-flex.flex-column.justify-content-center",
                          );
                          if (infoCol) {
                            infoCol.insertAdjacentHTML(
                              "beforeend",
                              '<div class="text-warning small fw-bold mt-1" style="font-size: 0.75rem;"><i class="bi bi-cloud-slash-fill"></i> Not cached offline</div>',
                            );
                          }
                          if (!navigator.onLine) {
                            item.style.pointerEvents = "none";
                          }
                        }
                      });
                    }
                  });
                }
              }
            } catch (e) {
              console.error(e);
              if (!append && this.listOfflineEl)
                this.listOfflineEl.innerHTML =
                  '<div style="text-align:center; padding: 24px; color: #aaa;">Failed to load offline tracks.</div>';
            }
            this.isLoadingOffline = false;
          }
    
          async loadArtistFollowing(artistUserId, append = false) {
            if (!append) {
              this.artistFollowingPage = 1;
              const container = document.getElementById("rg-list-artist-following");
              if (container) {
                container.innerHTML = `
                      <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                        <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                      </div>`;
              }
              this.allArtistFollowingLoaded = false;
            }
            if (this.allArtistFollowingLoaded) return;
            this.isLoadingArtistFollowing = true;
    
            const sort = document.getElementById("rg-sort-artist-following").value;
            const q = document
              .getElementById("rg-search-artist-following")
              .value.trim();
    
            let url = `?action=get_rhythm_artists&following_of=${artistUserId}&page=${this.artistFollowingPage}&sort=${sort}`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              const res = await fetchData(url, {}, true);
              const container = document.getElementById("rg-list-artist-following");
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && container)
                  container.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">Not following anyone.</div>';
                this.allArtistFollowingLoaded = true;
              } else {
                if (!append && container) container.innerHTML = "";
                res.forEach((entry) => {
                  const artist = {
                    name: entry.name,
                    id: entry.id || 0,
                    count: parseInt(entry.count || 0),
                    score: parseInt(entry.score || 0),
                    plays: parseInt(entry.plays || 0),
                    followers: parseInt(entry.followers || 0),
                    user_id: entry.user_id,
                    rankInfo: null,
                  };
                  const total =
                    parseInt(entry.perfect) +
                    parseInt(entry.great) +
                    parseInt(entry.good) +
                    parseInt(entry.bad) +
                    parseInt(entry.miss);
                  artist.rankInfo =
                    total > 0
                      ? this.getRankFromStats(
                          entry.perfect,
                          entry.great,
                          entry.good,
                          entry.bad,
                          entry.miss,
                        )
                      : {
                          rank: "F",
                          color: "#555",
                        };
                  if (container)
                    container.appendChild(this.createArtistElement(artist));
                });
                if (res.length < 25) this.allArtistFollowingLoaded = true;
              }
            } catch (e) {
              console.error(e);
            }
            this.isLoadingArtistFollowing = false;
          }
    
          generateChartSVG() {
            if (!this.chartNotes || this.chartNotes.length === 0) return "";
            const lanes = this.LANES;
            const duration = this.audioBuffer ? this.audioBuffer.duration : 180;
            // Dynamically widen lanes so 10 lanes don't look cramped
            const laneWidth = lanes === 10 ? 45 : 40;
            const width = lanes * laneWidth + 20;
            const pxPerSec = 150;
            const height = Math.ceil(duration * pxPerSec) + 40;
    
            let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" style="width: 100%; height: auto; max-width: ${width}px; background:#0a0a0a; display: block; margin: 0 auto;">`;
    
            // Draw lane dividers
            for (let i = 0; i <= lanes; i++) {
              svg += `<line x1="${10 + i * laneWidth}" y1="0" x2="${10 + i * laneWidth}" y2="${height}" stroke="#333" stroke-width="2"/>`;
            }
    
            // Draw horizontal measure lines (every 1 second)
            for (let t = 0; t <= Math.ceil(duration); t++) {
              const y = height - t * pxPerSec - 20;
              svg += `<line x1="10" y1="${y}" x2="${10 + lanes * laneWidth}" y2="${y}" stroke="#222" stroke-width="1"/>`;
            }
    
            // Draw notes
            this.chartNotes.forEach((n) => {
              const x = 10 + n.lane * laneWidth;
              const yBottom = height - n.time * pxPerSec - 20; // Bottom edge of note (since time goes up)
    
              const noteW = laneWidth - 8;
              const offsetX = x + 4;
    
              if (n.isLong) {
                const yTop = height - n.endTime * pxPerSec - 20;
                const h = Math.max(5, yBottom - yTop);
                if (n.hitEndSwipe) {
                  svg += `<rect x="${offsetX}" y="${yTop}" width="${noteW}" height="${h}" fill="rgba(255, 0, 255, 0.4)" stroke="#ff00ff" stroke-width="2" rx="4"/>`;
                  svg += `<rect x="${offsetX}" y="${yTop - 8}" width="${noteW}" height="16" fill="#ff00ff" stroke="#fff" stroke-width="2" rx="4"/>`;
                } else {
                  svg += `<rect x="${offsetX}" y="${yTop}" width="${noteW}" height="${h}" fill="rgba(255, 59, 48, 0.4)" stroke="#ff3b30" stroke-width="2" rx="4"/>`;
                  svg += `<rect x="${offsetX}" y="${yTop - 8}" width="${noteW}" height="16" fill="#ff3b30" stroke="#000" stroke-width="2" rx="4"/>`;
                }
              } else {
                if (n.isSwipe) {
                  svg += `<rect x="${offsetX}" y="${yBottom - 8}" width="${noteW}" height="16" fill="#ff00ff" stroke="#fff" stroke-width="2" rx="4"/>`;
                } else {
                  svg += `<rect x="${offsetX}" y="${yBottom - 8}" width="${noteW}" height="16" fill="#fff" stroke="#ff3b30" stroke-width="2" rx="4"/>`;
                }
              }
            });
    
            // Draw hit bar at y=height-20
            svg += `<line x1="5" y1="${height - 20}" x2="${15 + lanes * laneWidth}" y2="${height - 20}" stroke="#ff3b30" stroke-width="4"/>`;
    
            svg += `</svg>`;
            return svg;
          }
    
          async openPlayDialog(song) {
            this.selectedSong = song;
            document.getElementById("rg-dialog-song-name").textContent = song.title;
            const dialogArtistEl = document.getElementById("rg-dialog-song-artist");
            if (dialogArtistEl)
              dialogArtistEl.textContent = song.artist || "Unknown Artist";
    
            // Set Favorite UI state
            const isFav = song.rg_favorite == 1;
            const favIcon = document.getElementById("rg-dialog-fav-icon");
            const favText = document.getElementById("rg-dialog-fav-text");
            const favBtn = document.getElementById("rg-dialog-fav-btn");
            if (favIcon && favText && favBtn) {
              favIcon.className = isFav
                ? "bi bi-heart-fill text-danger"
                : "bi bi-heart";
              favText.textContent = isFav ? "Unfavorite" : "Favorite";
              favBtn.classList.toggle("text-danger", isFav);
            }
    
            // Set dynamic blurred background
            const playDialog = document.getElementById("rg-dialog-play");
            if (playDialog) {
              playDialog.style.backgroundColor = "transparent";
              let playBg = document.getElementById("rg-play-dialog-bg");
              if (!playBg) {
                playBg = document.createElement("div");
                playBg.id = "rg-play-dialog-bg";
                playBg.style.cssText =
                  "position:absolute; top:-40px; left:-40px; right:-40px; bottom:-40px; background-size:cover; background-position:center; filter:blur(40px) brightness(0.35); z-index:0; pointer-events:none; transition: background-image 0.5s ease;";
                playDialog.insertBefore(playBg, playDialog.firstChild);
              }
              if (song) {
                playBg.style.backgroundImage = `url('/cover/${song.id}')`;
              }
            }
    
            const warningId = "rg-offline-play-warning";
            let warningEl = document.getElementById(warningId);
            if (!navigator.onLine) {
              if (!warningEl) {
                const buttonContainer =
                  document.getElementById("rg-btn-dialog-play").parentNode;
                buttonContainer.insertAdjacentHTML(
                  "beforebegin",
                  `
                        <div id="${warningId}" class="alert border-warning text-warning small mb-3 text-start rounded-3" style="background: rgba(255,193,7,0.1); width: 100%; max-width: 360px; margin: 16px auto 0 auto; line-height: 1.4;">
                          <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Offline Mode:</strong> Played song information/scores will <strong>NOT</strong> be stored in the database, even after you go back online. Only online plays are saved.
                        </div>
                      `,
                );
              }
            } else {
              if (warningEl) warningEl.remove();
            }
    
            const coverEl = document.getElementById("rg-dialog-cover");
            if (coverEl)
              coverEl.src = `/cover/${song.id}`;
    
            // Clear previous modal chart content
            const chartContent = document.getElementById("rg-chart-modal-content");
            if (chartContent) chartContent.innerHTML = "";
    
            const lvlData = await fetchData(
              `?action=get_rhythm_levels&song_id=${song.id}`,
              {},
              true,
            );
            const diffMap = {
              easy: "Easy",
              medium: "Med",
              hard: "Hard",
              expert: "Exprt",
              master: "Mstr",
              demon: "Demon",
            };
    
            document.querySelectorAll(".rg-diff-btn").forEach((btn) => {
              const diffKey = btn.dataset.diff;
              const defaultText = diffMap[diffKey] || "Diff";
    
              if (
                lvlData &&
                lvlData.levels &&
                lvlData.levels[diffKey] !== undefined
              ) {
                btn.innerHTML = `<div class="rg-diff-btn-inner">${defaultText} <span class="lvl" style="font-size:0.75rem; font-weight:900; opacity:0.9;">LV.${lvlData.levels[diffKey]}</span></div>`;
              } else {
                btn.innerHTML = `<div class="rg-diff-btn-inner">${defaultText} <span class="lvl" style="font-size:0.75rem; font-weight:900; opacity:0.5;">LV.--</span></div>`;
              }
            });
    
            // Sync Demon warning state when opening the dialog
            const demonWarning = document.getElementById("rg-demon-warning");
            if (demonWarning) {
              if (this.selectedDiff === "demon") {
                demonWarning.style.maxHeight = "120px";
                demonWarning.style.opacity = "1";
                demonWarning.style.marginTop = "16px";
                demonWarning.style.borderWidth = "1px";
              } else {
                demonWarning.style.maxHeight = "0";
                demonWarning.style.opacity = "0";
                demonWarning.style.marginTop = "0";
                demonWarning.style.borderWidth = "0";
              }
            }
    
            const container = document.getElementById("rg-song-leaderboard");
            container.innerHTML =
              '<div class="text-center text-secondary small py-2"><div class="spinner-border spinner-border-sm text-danger" style="border-width: 0.15em;"></div> Loading scores...</div>';
            const apToggle = document.getElementById("rg-autoplay-toggle");
            if (apToggle) apToggle.checked = false;
            document.getElementById("rg-dialog-play").classList.remove("rg-hidden");
    
            try {
              const data = await fetchData(
                `?action=get_song_rhythm_leaderboard&song_id=${song.id}`,
                {},
                true,
              );
              if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML =
                  '<div class="text-center text-secondary small py-4">No scores recorded yet. Be the first!</div>';
                return;
              }
    
              container.innerHTML = data
                .map((entry, idx) => {
                  const jud = this.getRankFromStats(
                    entry.perfect,
                    entry.great,
                    entry.good,
                    entry.bad,
                    entry.miss,
                  );
                  const pfpUrl = `?action=get_profile_picture&id=${entry.user_id}`;
                  const isFullCombo =
                    parseInt(entry.miss || 0) === 0 && parseInt(entry.bad || 0) === 0;
                  const fcBadge = isFullCombo
                    ? '<span class="badge bg-success ms-2" style="font-size: 0.65rem; padding: 2px 6px;">FC</span>'
                    : "";
                  const totalHits =
                    parseInt(entry.perfect || 0) +
                    parseInt(entry.great || 0) +
                    parseInt(entry.good || 0) +
                    parseInt(entry.bad || 0) +
                    parseInt(entry.miss || 0);
                  const suspicious =
                    (jud.acc === "100.00" && totalHits > 50) ||
                    (entry.max_combo > 500 && entry.miss == 0)
                      ? '<span class="badge bg-danger ms-2" style="font-size: 0.6rem;" title="Perfect Play / Bot Suspected"><i class="bi bi-exclamation-triangle-fill"></i> SUS</span>'
                      : "";
    
                  return `
                        <div class="rg-list-item" style="background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.01) 100%); border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid ${jud.color}; border-radius: 12px; padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; cursor: default; margin-bottom: 8px; text-align: left;">
                          <div class="d-flex align-items-center justify-content-between gap-3" style="min-width: 0; width: 100%;">
                            <div style="font-size: 1.1rem; font-weight: 900; color: var(--rg-primary); width: 24px; text-align: center; font-family: monospace;">${idx + 1}</div>
                            <div class="flex-grow-1" style="min-width: 0; display: flex; align-items: center; justify-content: space-between;">
                              <div class="d-flex flex-column align-items-start">
                                <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Accuracy</span>
                                <span style="font-size: 1.1rem; font-weight: 800; font-family: monospace; color: #fff;">${jud.acc}%</span>
                              </div>
                              <div class="d-flex flex-column align-items-end">
                                <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Rank</span>
                                <span style="font-size: 1.8rem; font-weight: 900; font-family: 'Arial Black', sans-serif; color: ${jud.color}; text-shadow: 0 0 8px ${jud.color}; line-height: 1;">${jud.rank}</span>
                              </div>
                            </div>
                          </div>
    
                          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 100%; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 8px; margin-top: 4px; text-align: center;">
                            <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                              <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Score</span>
                              <span style="font-size: 0.95rem; font-weight: 900; font-family: monospace; color: var(--rg-primary);">${parseInt(entry.score).toLocaleString()}</span>
                            </div>
                            <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                              <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Combo</span>
                              <span style="font-size: 0.95rem; font-weight: 900; font-family: monospace; color: #fff;">${entry.max_combo}x${fcBadge}</span>
                            </div>
                            <div style="font-size: 0.75rem;"><span style="color:#aaa; display:block; font-size:0.6rem; text-transform:uppercase;">Perf</span><strong style="font-family: monospace; color:#fff;">${entry.perfect}</strong></div>
                            <div style="font-size: 0.75rem;"><span style="color:#ff3b30; display:block; font-size:0.6rem; text-transform:uppercase;">Great</span><strong style="font-family: monospace; color:#fff;">${entry.great}</strong></div>
                            <div style="font-size: 0.75rem;"><span style="color:#ffa000; display:block; font-size:0.6rem; text-transform:uppercase;">Good</span><strong style="font-family: monospace; color:#fff;">${entry.good}</strong></div>
                            <div style="font-size: 0.75rem;"><span style="color:#555; display:block; font-size:0.6rem; text-transform:uppercase;">Miss</span><strong style="font-family: monospace; color:#fff;">${entry.miss}</strong></div>
                          </div>
    
                          <div style="display: flex; align-items: center; gap: 8px; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 8px; margin-top: 4px; width: 100%;">
                            <img src="${pfpUrl}" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                            <span class="text-white text-truncate fw-medium" style="font-size: 0.85rem; max-width: 220px;">${escapeHTML(entry.player_name)}${suspicious}</span>
                          </div>
                        </div>
                      `;
                })
                .join("");
            } catch (err) {
              console.error("Failed to load song specific leaderboard:", err);
              container.innerHTML =
                '<div class="text-center text-danger small py-2">Failed to load scores.</div>';
            }
          }
    
          openPlayerStats(userId, name, page = 1) {
            const body = document.getElementById("rg-player-stats-body");
            const modalEl = document.getElementById("rg-player-stats-modal");
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    
            if (page === 1) {
              body.innerHTML = `
                      <div class="text-center mb-4">
                        <img src="?action=get_profile_picture&id=${userId}" class="rounded-circle shadow-lg mb-2" style="width: 80px; height: 80px; object-fit: cover; border: 2px solid var(--rg-primary);">
                        <h4 class="text-white fw-bold">${escapeHTML(name)}</h4>
                        <p class="text-secondary small">Rhythm Game Played Tracks</p>
                      </div>
                      <div id="rg-player-scores-list" class="d-flex flex-column gap-3">
                        <div class="text-center py-4"><div class="spinner-border text-danger"></div></div>
                      </div>
                    `;
              modal.show();
              this.currentStatsPage = 1;
              this.statsUserId = userId;
              this.statsPlayerName = name;
            } else {
              const loadMoreBtn = document.getElementById("rg-btn-load-more-stats");
              if (loadMoreBtn)
                loadMoreBtn.innerHTML =
                  '<span class="spinner-border spinner-border-sm"></span> Loading...';
            }
    
            fetchData(
              `?action=get_user_rhythm_scores&target_user_id=${userId}&page=${page}`,
            ).then((scores) => {
              const listContainer = document.getElementById("rg-player-scores-list");
              const oldBtn = document.getElementById("load-more-stats-container");
              if (oldBtn) oldBtn.remove();
    
              if (scores && scores.length > 0) {
                const diffColors = {
                  easy: "#22d3ee",
                  medium: "#34d399",
                  hard: "#fb923c",
                  expert: "#f43f5e",
                  master: "#a78bfa",
                  demon: "#7f1d1d",
                };
                const diffLabels = {
                  easy: "EZ",
                  medium: "ND",
                  hard: "HD",
                  expert: "EX",
                  master: "MS",
                  demon: "DM",
                };
    
                const cardsHtml = scores
                  .map((s) => {
                    const jud = this.getRankFromStats(
                      s.perfect,
                      s.great,
                      s.good,
                      s.bad,
                      s.miss,
                    );
                    const diffColor = diffColors[s.difficulty] || "#aaa";
                    const diffLabel = diffLabels[s.difficulty] || "UN";
                    const lvlText = s.level ? `Lv.${s.level}` : "Lv.--";
                    const coverSvg = getSvgPlaceholder(s.title);
                    const playedAt = s.played_at
                      ? new Date(
                          s.played_at.replace(" ", "T") + "Z",
                        ).toLocaleDateString()
                      : "Unknown";
                    const totalHits =
                      parseInt(s.perfect || 0) +
                      parseInt(s.great || 0) +
                      parseInt(s.good || 0) +
                      parseInt(s.bad || 0) +
                      parseInt(s.miss || 0);
                    const suspicious =
                      (jud.acc === "100.00" && totalHits > 50) ||
                      (s.max_combo > 500 && s.miss == 0)
                        ? '<span class="badge bg-danger ms-2" style="font-size: 0.6rem;" title="Perfect Play / Bot Suspected"><i class="bi bi-exclamation-triangle-fill"></i> SUS</span>'
                        : "";
    
                    return `
                          <div class="rg-list-item" style="background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.01) 100%); border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid ${diffColor}; border-radius: 12px; padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; cursor: default;">
                            <div class="d-flex align-items-center justify-content-between gap-3" style="min-width: 0; width: 100%;">
                              <div class="d-flex align-items-center gap-3" style="min-width: 0; flex: 1;">
                                <img src="/cover/${s.song_id}" onerror="this.onerror=null; this.src='${coverSvg}'" style="width: 48px; height: 48px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 8px rgba(0,0,0,0.4); flex-shrink: 0;">
                                <div class="text-truncate" style="min-width: 0; flex: 1;">
                                  <div class="text-white fw-bold text-truncate" style="font-size: 1rem;">${escapeHTML(s.title)}</div>
                                  <div class="text-secondary text-truncate small" style="font-size: 0.8rem; margin-top: 1px;">${escapeHTML(s.artist)}</div>
                                </div>
                              </div>
                              <div class="d-flex flex-column align-items-end" style="flex-shrink: 0;">
                                <div class="d-flex align-items-center gap-1">
                                  ${suspicious}
                                  <span class="badge d-flex align-items-center justify-content-center" style="font-family: monospace; font-size: 0.65rem; font-weight: 800; background-color: ${diffColor}; color: ${s.difficulty === "demon" ? "#fff" : "#000"}; padding: 2px 6px; border-radius: 4px;" title="${s.difficulty.toUpperCase()} level">${diffLabel} ${lvlText}</span>
                                  <span class="badge d-flex align-items-center justify-content-center" style="font-family: monospace; font-size: 0.65rem; font-weight: 800; background-color: ${jud.color}; color: #000;" title="RANK">${jud.rank}</span>
                                </div>
                                <div class="text-secondary small mt-1" style="font-size: 0.75rem;">${playedAt}</div>
                              </div>
                            </div>
    
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 100%; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 8px; margin-top: 4px; text-align: center;">
                              <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                                <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Score</span>
                                <span style="font-size: 0.95rem; font-weight: 900; font-family: monospace; color: var(--rg-primary);">${parseInt(s.score).toLocaleString()}</span>
                              </div>
                              <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                                <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Combo</span>
                                <span style="font-size: 0.95rem; font-weight: 900; font-family: monospace; color: #fff;">${s.max_combo}x</span>
                              </div>
                              <div style="font-size: 0.75rem;"><span style="color:#aaa; display:block; font-size:0.6rem; text-transform:uppercase;">Perf</span><strong style="font-family: monospace; color:#fff;">${s.perfect}</strong></div>
                              <div style="font-size: 0.75rem;"><span style="color:#ff3b30; display:block; font-size:0.6rem; text-transform:uppercase;">Great</span><strong style="font-family: monospace; color:#fff;">${s.great}</strong></div>
                              <div style="font-size: 0.75rem;"><span style="color:#ffa000; display:block; font-size:0.6rem; text-transform:uppercase;">Good</span><strong style="font-family: monospace; color:#fff;">${s.good}</strong></div>
                              <div style="font-size: 0.75rem;"><span style="color:#555; display:block; font-size:0.6rem; text-transform:uppercase;">Miss</span><strong style="font-family: monospace; color:#fff;">${s.miss}</strong></div>
                            </div>
                          </div>
                        `;
                  })
                  .join("");
    
                if (page === 1) {
                  listContainer.innerHTML = cardsHtml;
                } else {
                  listContainer.insertAdjacentHTML("beforeend", cardsHtml);
                }
    
                if (scores.length === 25) {
                  listContainer.insertAdjacentHTML(
                    "afterend",
                    `
                          <div class="text-center p-3" id="load-more-stats-container">
                            <button class="btn btn-outline-light rounded-pill px-4 btn-sm fw-bold" id="rg-btn-load-more-stats">Load More History</button>
                          </div>
                        `,
                  );
                  document.getElementById("rg-btn-load-more-stats").onclick = () => {
                    this.currentStatsPage++;
                    this.openPlayerStats(
                      this.statsUserId,
                      this.statsPlayerName,
                      this.currentStatsPage,
                    );
                  };
                } else if (page > 1) {
                  listContainer.insertAdjacentHTML(
                    "afterend",
                    '<div class="text-center p-3 text-secondary small" id="load-more-stats-container">No more plays recorded.</div>',
                  );
                }
              } else {
                if (page === 1) {
                  listContainer.innerHTML =
                    '<div class="text-center text-secondary py-5">No play history recorded yet for this user.</div>';
                }
              }
            });
          }
    
          sortSongs(method) {}
          renderSongsList() {}
          sortFavorites(method) {}
          renderFavoritesList() {}
    
          switchScreen(name) {
            Object.values(this.screens).forEach((s) => s.classList.add("rg-hidden"));
            if (this.screens[name]) this.screens[name].classList.remove("rg-hidden");

            // Forcefully hide any lingering overlay dialogs when transitioning between screens
            const pauseDialog = document.getElementById("rg-dialog-pause");
            if (pauseDialog) pauseDialog.classList.add("rg-hidden");
            const chartDialog = document.getElementById("rg-dialog-chart-modal");
            if (chartDialog) chartDialog.classList.add("rg-hidden");

            if (name === "game" || name === "loading") {
              document.body.classList.add("rg-session-active");
            } else {
              document.body.classList.remove("rg-session-active");
              if (name === "hub") {
                if (this.audioPlayback) {
                  this.audioPlayback.pause();
                  this.audioPlayback.src = "";
                }
                if (this.startGameTimeout) clearTimeout(this.startGameTimeout);
                this.isPlaying = false;
              }
            }
          }
    
          toggleCalibrationTest() {
            const area = document.getElementById("rg-cal-test-area");
            const btn = document.getElementById("rg-btn-cal-test");
            const fb = document.getElementById("rg-cal-feedback");
    
            this.isCalibrating = !this.isCalibrating;
    
            if (this.isCalibrating) {
              area.style.display = "block";
              btn.textContent = "Stop Test";
              btn.style.background = "#444";
              btn.style.color = "#fff";
              fb.textContent = "Tap Screen / Keys to Calibrate";
              fb.style.color = "#fff";
    
              this.calCanvas = document.getElementById("rg-cal-canvas");
              this.calCtx = this.calCanvas.getContext("2d");
              this.resizeCalCanvas();
    
              this.calNotes = [];
              this.calStartTime = Date.now();
    
              if (!this.sfxCtx) {
                this.sfxCtx = new (
                  window.AudioContext || window.webkitAudioContext
                )();
              }
    
              this.boundCalTouchStart = (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.processCalHit();
              };
              this.boundCalMouseDown = (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.processCalHit();
              };
              area.addEventListener("touchstart", this.boundCalTouchStart, {
                passive: false,
              });
              area.addEventListener("mousedown", this.boundCalMouseDown);
    
              this.runCalibrationLoop();
            } else {
              area.style.display = "none";
              btn.textContent = "Start Test";
              btn.style.background = "var(--rg-primary)";
              btn.style.color = "#fff";
    
              if (this.boundCalTouchStart)
                area.removeEventListener("touchstart", this.boundCalTouchStart);
              if (this.boundCalMouseDown)
                area.removeEventListener("mousedown", this.boundCalMouseDown);
    
              cancelAnimationFrame(this.calAnimId);
              this.calNotes = [];
            }
          }
    
          resizeCalCanvas() {
            if (!this.calCanvas) return;
            const parent = this.calCanvas.parentNode;
            this.calCanvas.width = parent.offsetWidth;
            this.calCanvas.height = parent.offsetHeight;
          }
    
          runCalibrationLoop() {
            if (!this.isCalibrating) return;
    
            const now = (Date.now() - this.calStartTime) / 1000;
            const w = this.calCanvas.width;
            const h = this.calCanvas.height;
    
            this.calCtx.fillStyle = "#000";
            this.calCtx.fillRect(0, 0, w, h);
    
            const targetY = h * 0.75;
            const speed = 150 * this.userTickSpeed;
    
            if (
              this.calNotes.length === 0 ||
              now - this.calNotes[this.calNotes.length - 1].time > 1.0
            ) {
              this.calNotes.push({
                time: now + 1.2,
                hit: false,
                missed: false,
              });
            }
    
            this.calCtx.strokeStyle = "var(--rg-primary)";
            this.calCtx.lineWidth = 2;
            this.calCtx.beginPath();
            this.calCtx.moveTo(0, targetY);
            this.calCtx.lineTo(w, targetY);
            this.calCtx.stroke();
    
            this.calNotes.forEach((n) => {
              if (n.hit || n.missed) return;
              const delta = n.time - now;
              const y = targetY - delta * speed;
    
              if (y > h) {
                n.missed = true;
                return;
              }
              if (y >= 0 && y < h) {
                this.calCtx.strokeStyle = "#fff";
                this.calCtx.lineWidth = 3;
                this.calCtx.beginPath();
                this.calCtx.moveTo(w * 0.2, y);
                this.calCtx.lineTo(w * 0.8, y);
                this.calCtx.stroke();
              }
            });
    
            this.calAnimId = requestAnimationFrame(this.boundCalLoop);
          }
    
          processCalHit() {
            this.playHitSFX();
            if (navigator.vibrate) navigator.vibrate(20);
            const now = (Date.now() - this.calStartTime) / 1000;
            let minDiff = Infinity;
            let target = null;
    
            this.calNotes.forEach((n) => {
              if (!n.hit && !n.missed) {
                const diff = now - n.time;
                if (Math.abs(diff) < Math.abs(minDiff)) {
                  minDiff = diff;
                  target = n;
                }
              }
            });
    
            if (target && Math.abs(minDiff) < 0.3) {
              target.hit = true;
              const fb = document.getElementById("rg-cal-feedback");
              const diffMs = Math.round(minDiff * 1000);
    
              if (fb) {
                if (Math.abs(diffMs) <= 45) {
                  fb.textContent = `PERFECT (${diffMs >= 0 ? "+" : ""}${diffMs}ms)`;
                  fb.style.color = "#27c93f";
                } else {
                  const isLate = diffMs > 0;
                  fb.textContent = `${isLate ? "LATE" : "EARLY"} (${diffMs >= 0 ? "+" : ""}${diffMs}ms)`;
                  fb.style.color = isLate ? "#ff5f56" : "#ffbd2e";
                }
                fb.style.transform = "translate(-50%, -50%) scale(1.1)";
                setTimeout(() => {
                  fb.style.transform = "translate(-50%, -50%) scale(1)";
                }, 50);
              }
            }
          }
    
          onKeyDown(e) {
            if (this.listeningLane !== null) {
              this.KEY_CODES[this.listeningLane] = e.code;
              let label = e.key.toUpperCase();
              if (e.code.startsWith("Key")) label = e.code.replace("Key", "");
              else if (e.code === "Space") label = "SPC";
              this.KEY_LABELS[this.listeningLane] = label;
    
              localStorage.setItem("rg_key_codes", JSON.stringify(this.KEY_CODES));
              localStorage.setItem("rg_key_labels", JSON.stringify(this.KEY_LABELS));
    
              const btn = document.querySelector(
                `.rg-key-btn[data-lane="${this.listeningLane}"]`,
              );
              if (btn) {
                btn.textContent = label;
                btn.classList.remove("listening");
              }
              this.listeningLane = null;
              e.preventDefault();
            } else if (this.isPlaying && !e.repeat) {
              if (this.isAutoplay) return; // Disables physical keyboard inputs during Bot Mode sessions
              const idx = this.activeCodes.indexOf(e.code);
              if (idx !== -1) {
                this.activeKeysHeld[idx] = true;
                this.processHit(idx, true, false, true); // Passes isDesktopKeyboard flag
                e.preventDefault();
                e.stopPropagation();
              }
            }
          }
    
          onKeyUp(e) {
            if (this.isPlaying) {
              if (this.isAutoplay) return; // Disables physical key releases during Bot Mode sessions
              const idx = this.activeCodes.indexOf(e.code);
              if (idx !== -1) {
                this.activeKeysHeld[idx] = false;
                this.processHit(idx, true, true, true); // Desktop keyup triggers swipe
              }
            }
          }
    
          createSongElement(song) {
            const el = document.createElement("div");
            el.className = "rg-list-item position-relative overflow-hidden";
            el.dataset.songId = song.id;
    
            if (song.levels) {
              if (!window.rhythmLevelsMap)
                window.rhythmLevelsMap = {
                  levels: {},
                };
              if (!window.rhythmLevelsMap.levels) window.rhythmLevelsMap.levels = {};
              window.rhythmLevelsMap.levels[song.id] = song.levels;
            }
    
            const levels =
              window.rhythmLevelsMap &&
              window.rhythmLevelsMap.levels &&
              window.rhythmLevelsMap.levels[song.id]
                ? window.rhythmLevelsMap.levels[song.id]
                : null;
            let badgesHTML = "";
    
            if (levels) {
              const diffs = [
                {
                  short: "EZ",
                  key: "easy",
                  bg: "#6EE7B7",
                  text: "#064E3B",
                },
                {
                  short: "NM",
                  key: "medium",
                  bg: "#60A5FA",
                  text: "#1E3A8A",
                },
                {
                  short: "HD",
                  key: "hard",
                  bg: "#FBBF24",
                  text: "#78350F",
                },
                {
                  short: "EX",
                  key: "expert",
                  bg: "#F43F5E",
                  text: "#FFFFFF",
                },
                {
                  short: "MS",
                  key: "master",
                  bg: "#A78BFA",
                  text: "#FFFFFF",
                },
                {
                  short: "DM",
                  key: "demon",
                  bg: "#4C1D95",
                  text: "#FFFFFF",
                },
              ];
              let badgesList = diffs
                .map((d) => {
                  const lvl = levels[d.key];
                  if (!lvl) return "";
                  return `<span class="badge d-flex align-items-center justify-content-center px-2 py-1 shadow-sm" style="font-family: 'Arial Black', sans-serif; font-size: 0.7rem; font-weight: 900; background-color: ${d.bg}; color: ${d.text}; border-radius: 6px; letter-spacing: 0.5px; width: 90%; text-align: center;" title="${d.key.toUpperCase()} Level">${d.short} ${lvl}</span>`;
                })
                .filter((b) => b !== "")
                .join("");
    
              if (badgesList !== "") {
                badgesHTML = `<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-top: 8px; max-width: 175px;">${badgesList}</div>`;
              } else {
                badgesHTML = `<div class="text-secondary small mt-2 fw-bold" style="font-size: 0.75rem;"><i class="bi bi-cpu-fill me-1"></i>Tap to compile notes</div>`;
              }
            } else {
              badgesHTML = `<div class="text-secondary small mt-2 fw-bold" style="font-size: 0.75rem;"><i class="bi bi-cpu-fill me-1"></i>Tap to compile notes</div>`;
            }
    
            el.style.cssText =
              "background: linear-gradient(90deg, rgba(30, 30, 30, 0.9) 0%, rgba(15, 15, 15, 0.95) 100%); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 16px 14px; display: flex; align-items: center; gap: 14px; cursor: pointer; position: relative; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s, border-color 0.2s; margin-bottom: 0.25em; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4); min-height: 10em;";
    
            el.onmouseover = () => {
              el.style.transform = "scale(1.02) translateY(-2px)";
              el.style.borderColor = "var(--rg-primary)";
              el.style.boxShadow = "0 10px 25px rgba(255,59,48,0.2)";
            };
            el.onmouseout = () => {
              el.style.transform = "none";
              el.style.borderColor = "rgba(255,255,255,0.08)";
              el.style.boxShadow = "0 6px 16px rgba(0,0,0,0.4)";
            };
    
            el.innerHTML = `
                    <div class="d-flex align-items-center gap-3 w-100" style="min-width: 0;">
                      <div class="position-relative" style="width: 80px; height: 80px; flex-shrink: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.6);">
                        <img src="/cover/${song.id}" onerror="this.onerror=null; this.src='${getSvgPlaceholder(song.title)}';" style="width: 100%; height: 100%; object-fit: cover;">
                        <div class="position-absolute bottom-0 start-0 w-100 h-50" style="background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);"></div>
                        <div class="position-absolute bottom-0 start-0 w-100 p-1 d-flex align-items-center justify-content-center gap-1 text-white" style="font-size: 0.65rem; font-weight: bold;">
                          <i class="bi bi-play-fill text-danger"></i> ${formatSongCount(song.play_count || 0)}
                        </div>
                      </div>
    
                      <div class="d-flex flex-column justify-content-center" style="min-width: 0; flex-grow: 1; gap: 2px;">
                        <div class="text-white fw-bolder text-truncate" style="font-size: 1.15rem; letter-spacing: -0.2px; text-shadow: 0 2px 4px rgba(0,0,0,0.8);">${escapeHTML(song.title)}</div>
                        <div class="text-secondary fw-bold text-truncate artist-link" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id || ""}" style="font-size: 0.85rem; color: #a1a1aa !important; margin-top: -2px;">${escapeHTML(song.artist)}</div>
                        ${badgesHTML}
                      </div>
    
                      <div class="d-flex flex-column align-items-end justify-content-between ms-2" style="flex-shrink: 0; align-self: stretch; min-height: 84px; gap: 8px;">
                        <div class="d-flex gap-2">
                          <button class="btn p-1 border-0 rg-fav-btn ${song.rg_favorite == 1 ? "text-danger" : "text-secondary"}" style="background: transparent; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"><i class="bi ${song.rg_favorite == 1 ? "bi-heart-fill" : "bi-heart"} fs-5"></i></button>
                          <button class="btn p-1 border-0 rg-share-song-btn text-light" style="background: transparent; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"><i class="bi bi-share-fill fs-5"></i></button>
                        </div>
                        <button class="btn p-0 border-0 rounded-circle rg-play-btn d-flex align-items-center justify-content-center mt-auto" style="width: 42px; height: 42px; background: linear-gradient(135deg, var(--rg-primary), #ff0055); box-shadow: 0 4px 12px rgba(255,59,48,0.5); transition: transform 0.2s;">
                          <i class="bi bi-play-fill text-white" style="font-size: 1.8rem; margin-left: 3px;"></i>
                        </button>
                      </div>
                    </div>
                  `;
    
            const favBtn = el.querySelector(".rg-fav-btn");
            const shareBtn = el.querySelector(".rg-share-song-btn");
            const playBtn = el.querySelector(".rg-play-btn");
    
            shareBtn.addEventListener("click", (e) => {
              e.stopPropagation();
              if (typeof showShareModal === "function") {
                showShareModal("game", song.id, song.title);
              }
            });
    
            favBtn.addEventListener("click", async (e) => {
              e.stopPropagation();
              const res = await fetchData("?action=toggle_rhythm_favorite", {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                },
                body: JSON.stringify({
                  song_id: song.id,
                }),
              });
              if (res) {
                song.rg_favorite = res.is_favorite;
                favBtn.classList.toggle("text-danger", song.rg_favorite == 1);
                favBtn.classList.toggle("text-secondary", song.rg_favorite != 1);
                favBtn.innerHTML = `<i class="bi ${song.rg_favorite == 1 ? "bi-heart-fill" : "bi-heart"} fs-5"></i>`;
    
                // If unfavorited and currently viewing the Favorites tab, fade out and remove card immediately
                if (song.rg_favorite != 1) {
                  const activeTab = localStorage.getItem("rg_active_tab") || "songs";
                  if (activeTab === "favorites") {
                    el.style.transition = "opacity 0.2s ease, transform 0.2s ease";
                    el.style.opacity = "0";
                    el.style.transform = "scale(0.95)";
                    setTimeout(() => el.remove(), 200);
                  }
                }
              }
            });
    
            // Bind Artist navigation when clicking the artist label
            const artistLink = el.querySelector(".artist-link");
            if (artistLink) {
              artistLink.addEventListener("click", (e) => {
                e.stopPropagation();
                const rawArtist = song.artist || "Unknown";
                const parts = rawArtist
                  .split(
                    /\s*(?:;|\||\s+&\s+|\s+feat\.?\s+|\s+ft\.?\s+|\s+featuring\s+|\s*,\s*(?!(?:the|a|an|jr|sr)\b))\s*/i,
                  )
                  .map((p) => p.trim())
                  .filter((p) => p !== "");
                if (parts.length > 1) {
                  this.openArtistChoiceDialog(parts);
                } else if (parts.length === 1) {
                  this.openArtistDetail(parts[0]);
                }
              });
            }
    
            // Allow playing by clicking anywhere on the card OR directly on the play button
            const initiatePlay = () => this.openPlayDialog(song);
            playBtn.addEventListener("click", (e) => {
              e.stopPropagation();
              initiatePlay();
            });
            el.addEventListener("click", (e) => {
              if (e.target.closest(".artist-link")) return;
              initiatePlay();
            });
    
            return el;
          }
    
          renderSongsList() {
            this.listSongsEl.innerHTML = "";
    
            if (
              window.rhythmLevelsMap &&
              window.rhythmLevelsMap.levels &&
              Object.keys(window.rhythmLevelsMap.levels).length === 0
            ) {
              const isAdmin =
                currentUser &&
                (currentUser.is_admin == 1 ||
                  currentUser.status === "super_admin");
              this.listSongsEl.insertAdjacentHTML(
                "beforeend",
                `
                      <div style="background: rgba(255, 59, 48, 0.1); border: 1px solid var(--rg-primary); border-radius: 16px; padding: 20px; margin-bottom: 16px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                        <i class="bi bi-exclamation-triangle-fill text-danger d-block mb-2" style="font-size: 2.5rem;"></i>
                        <h6 class="text-white fw-bold text-uppercase mb-2" style="letter-spacing: 1px;">No Charts Generated</h6>
                        <p class="text-secondary small mb-3">The database currently has no pre-compiled note charts. Songs will generate on-the-fly when clicked, which may cause a slight initial delay.</p>
                        ${isAdmin ? `<button class="btn btn-danger rounded-pill fw-bold px-4 py-2 shadow-sm" onclick="document.getElementById('nav-chart-scan').click();"><i class="bi bi-open-chart-scan me-1"></i> Run Server Scanner Now</button>` : `<div class="badge bg-dark border border-secondary text-secondary p-2"><i class="bi bi-lock-fill me-1"></i> Contact Admin to Run Scanner</div>`}
                      </div>
                    `,
              );
            }
    
            const chunk = this.filteredSongs.slice(0, this.renderLimit);
            if (chunk.length === 0) {
              this.listSongsEl.insertAdjacentHTML(
                "beforeend",
                '<div style="text-align:center; padding: 24px; color: #aaa;">No songs found.</div>',
              );
              return;
            }
            chunk.forEach((song) =>
              this.listSongsEl.appendChild(this.createSongElement(song)),
            );
          }
    
          renderFavoritesList() {
            this.listFavsEl.innerHTML = "";
            const chunk = this.favFilteredSongs.slice(0, this.favRenderLimit);
            if (chunk.length === 0) {
              this.listFavsEl.innerHTML =
                '<div style="text-align:center; padding: 24px; color: #aaa;">No favorite songs yet. Add some!</div>';
              return;
            }
            chunk.forEach((song) =>
              this.listFavsEl.appendChild(this.createSongElement(song)),
            );
          }
    
          calculateLevel(notes, duration, diff = "medium", density_multiplier = 1.0) {
            if (!duration || duration <= 0) return 1;
            const nps =
              notes.length /
              duration /
              Math.max(0.1, Math.min(10.0, density_multiplier));
            let level = 1;
            if (diff === "easy") level = Math.round(nps * 1.5) + 3;
            else if (diff === "medium") level = Math.round(nps * 2.0) + 8;
            else if (diff === "hard") level = Math.round(nps * 2.5) + 14;
            else if (diff === "expert") level = Math.round(nps * 3.0) + 21;
            else if (diff === "master") level = Math.round(nps * 3.5) + 26;
            else if (diff === "demon") level = Math.round(nps * 4.0) + 30;
            return Math.max(1, Math.min(40, level));
          }
    
          calcY(p) {
            return this.vanY + Math.pow(p, 1.35) * this.hwH;
          }
          calcW(p) {
            return (
              (this.topW + Math.pow(p, 1.35) * (this.botW - this.topW)) / this.LANES
            );
          }
          calcX(lane, p) {
            const cW = this.topW + Math.pow(p, 1.35) * (this.botW - this.topW);
            return this.wHalf - cW / 2 + lane * (cW / this.LANES);
          }
    
          drawPerspectiveNote(lane, pBottom, type) {
            let pTop = pBottom - 0.022; // Sleek thin note bodies (2.2% of track length)
            if (pTop < 0) pTop = 0;
    
            const yBot = this.calcY(pBottom);
            const yTop = this.calcY(pTop);
    
            const pad = 0.08; // Noticeable gap between lanes
            const wBot = this.calcW(pBottom);
            const xBotLeft = this.calcX(lane, pBottom) + wBot * pad;
            const xBotRight = this.calcX(lane, pBottom) + wBot - wBot * pad;
    
            const wTop = this.calcW(pTop);
            const xTopLeft = this.calcX(lane, pTop) + wTop * pad;
            const xTopRight = this.calcX(lane, pTop) + wTop - wTop * pad;
    
            // 1. Draw Outer Dark Border (Creates high contrast against background lanes)
            this.ctx.beginPath();
            this.ctx.moveTo(xBotLeft, yBot);
            this.ctx.lineTo(xBotRight, yBot);
            this.ctx.lineTo(xTopRight, yTop);
            this.ctx.lineTo(xTopLeft, yTop);
            this.ctx.closePath();
    
            this.ctx.lineJoin = "round";
            this.ctx.lineWidth = Math.max(5, 14 * pBottom);
            this.ctx.strokeStyle = "rgba(0, 0, 0, 0.6)";
            this.ctx.stroke();
    
            // Base Colors (Vibrant Project Sekai Palette)
            let mainColor, lightColor, shadowColor;
            if (type === "swipe") {
              mainColor = "#ff4da6"; // Hot Pink
              lightColor = "#ffb3df"; // Light Pink
              shadowColor = "#cc0066"; // Deep Magenta
            } else if (type === "hold" || type === "holdEnd") {
              mainColor = "#2ecc71"; // Emerald Green
              lightColor = "#a3ebd6"; // Mint Green
              shadowColor = "#1b8a47"; // Forest Green
            } else {
              mainColor = "#00d2ff"; // Cyan/Vibrant Blue
              lightColor = "#99efff"; // Ice Blue
              shadowColor = "#008da6"; // Deep Teal
            }
    
            // 2. Draw Main Filled Body
            this.ctx.fillStyle = mainColor;
            this.ctx.fill();
    
            this.ctx.lineWidth = Math.max(3, 10 * pBottom);
            this.ctx.strokeStyle = mainColor;
            this.ctx.stroke();
    
            // 3. Draw Bottom shadow inside the capsule
            this.ctx.beginPath();
            this.ctx.moveTo(xBotLeft, yBot);
            this.ctx.lineTo(xBotRight, yBot);
            this.ctx.strokeStyle = shadowColor;
            this.ctx.lineWidth = Math.max(1.5, 4 * pBottom);
            this.ctx.stroke();
    
            // 4. Draw Top Highlight
            this.ctx.beginPath();
            this.ctx.moveTo(xTopLeft, yTop);
            this.ctx.lineTo(xTopRight, yTop);
            this.ctx.strokeStyle = lightColor;
            this.ctx.lineWidth = Math.max(1.5, 4 * pBottom);
            this.ctx.stroke();
    
            // 5. Draw the iconic inner white horizontal bar
            const midY = (yBot + yTop) / 2;
            const midXLeft = (xBotLeft + xTopLeft) / 2;
            const midXRight = (xBotRight + xTopRight) / 2;
    
            this.ctx.beginPath();
            this.ctx.moveTo(midXLeft + 4, midY);
            this.ctx.lineTo(midXRight - 4, midY);
            this.ctx.strokeStyle = "#ffffff";
            this.ctx.lineWidth = Math.max(1.5, 4 * pBottom);
            this.ctx.lineCap = "round";
            this.ctx.stroke();
    
            if (type === "swipe") {
              // Beautiful sleek Chevron arrow inside the pink note
              const cX = (xBotLeft + xBotRight) / 2;
              const arrowH = (yBot - yTop) * 1.6;
              const arrowW = (xBotRight - xBotLeft) * 0.18;
    
              this.ctx.beginPath();
              this.ctx.moveTo(cX - arrowW, midY + arrowH / 6);
              this.ctx.lineTo(cX, midY - arrowH / 3);
              this.ctx.lineTo(cX + arrowW, midY + arrowH / 6);
    
              this.ctx.strokeStyle = "#ffffff";
              this.ctx.lineWidth = Math.max(2, 5 * pBottom);
              this.ctx.lineCap = "round";
              this.ctx.lineJoin = "round";
              this.ctx.stroke();
            }
          }
    
          pauseGame() {
            if (!this.isPlaying) return;
            this.isPlaying = false;
            if (this.audioPlayback) this.audioPlayback.pause();
            const pauseDialog = document.getElementById("rg-dialog-pause");
            if (pauseDialog) {
              pauseDialog.classList.remove("rg-hidden");
            }
          }
    
          getRankFromStats(perfect, great, good, bad, miss) {
            const total =
              parseInt(perfect || 0) +
              parseInt(great || 0) +
              parseInt(good || 0) +
              parseInt(bad || 0) +
              parseInt(miss || 0);
            if (total === 0)
              return {
                rank: "F",
                color: "#7f1d1d",
                acc: "0.00",
              };
            const accVal =
              (parseInt(perfect || 0) * 100 +
                parseInt(great || 0) * 75 +
                parseInt(good || 0) * 40 +
                parseInt(bad || 0) * 10) /
              total;
    
            if (accVal >= 98.0)
              return {
                rank: "SS",
                color: "#ff0055",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 95.0)
              return {
                rank: "S",
                color: "#ffbd2e",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 90.0)
              return {
                rank: "A",
                color: "#a78bfa",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 80.0)
              return {
                rank: "B",
                color: "#60a5fa",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 70.0)
              return {
                rank: "C",
                color: "#34d399",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 60.0)
              return {
                rank: "D",
                color: "#fb923c",
                acc: accVal.toFixed(2),
              };
            if (accVal >= 50.0)
              return {
                rank: "E",
                color: "#9ca3af",
                acc: accVal.toFixed(2),
              };
            return {
              rank: "F",
              color: "#7f1d1d",
              acc: accVal.toFixed(2),
            };
          }
    
          async prepGame(song, diff) {
            if (this.startGameTimeout) clearTimeout(this.startGameTimeout);
            this.switchScreen("loading");
            document.getElementById("rg-loading-cover").src =
              `/cover/${song.id}`;
            document.getElementById("rg-loading-title").textContent = song.title;
            const loadingArtistEl = document.getElementById("rg-loading-artist");
            if (loadingArtistEl)
              loadingArtistEl.textContent = song.artist || "Unknown Artist";
            const progressText = document.getElementById("rg-loading-text");
            progressText.textContent = `Preparing audio... 0%`;
    
            try {
              let playableUrl = `/stream/${song.id}`;
              let isCachedLocally = false;
    
              if (this.rgSongCache[song.id]) {
                progressText.textContent = `Loading from memory...`;
                playableUrl = this.rgSongCache[song.id].blobUrl;
                isCachedLocally = true;
              } else {
                progressText.textContent = `Checking local storage...`;
                
                // 1. Instantly attempt to extract from ultra-fast OPFS hardware cache
                try {
                  if (navigator.storage && navigator.storage.getDirectory) {
                    const root = await navigator.storage.getDirectory();
                    const dir = await root.getDirectoryHandle("offline_music_cache");
                    const fileHandle = await dir.getFileHandle(`song_${song.id}`);
                    const file = await fileHandle.getFile();
                    playableUrl = URL.createObjectURL(file);
                    this.rgSongCache[song.id] = { blobUrl: playableUrl };
                    isCachedLocally = true;
                  }
                } catch(e) {}
    
                // 2. Fallback to standard Cache API if OPFS missed
                if (!isCachedLocally) {
                  const cacheRes = await caches.match(`/stream/${song.id}`, { ignoreSearch: false, ignoreVary: true });
                  if (cacheRes) {
                    const blob = await cacheRes.blob();
                    playableUrl = URL.createObjectURL(blob);
                    this.rgSongCache[song.id] = { blobUrl: playableUrl };
                    isCachedLocally = true;
                  }
                }
              }
    
              // 3. Fallback to Network Download ONLY if it was never cached
              if (!isCachedLocally) {
                if (!navigator.onLine) {
                  throw new Error("Song not available offline.");
                }
                progressText.textContent = `Connecting to stream...`;
                const response = await fetch(`/stream/${song.id}`);
                const contentLength = response.headers.get("content-length");
    
                if (contentLength && parseInt(contentLength) > 1000000) {
                  // If > 1MB, show download progress
                  const total = parseInt(contentLength, 10);
                  let loaded = 0;
                  const reader = response.body.getReader();
                  const chunks = [];
                  while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    chunks.push(value);
                    loaded += value.length;
                    const pct = Math.round((loaded / total) * 100);
                    progressText.textContent = `Loading... ${pct}%`;
                  }
                  const blob = new Blob(chunks, {
                    type: response.headers.get("content-type") || "audio/mpeg",
                  });
                  playableUrl = URL.createObjectURL(blob);
                  this.rgSongCache[song.id] = {
                    blobUrl: playableUrl,
                  };
                } else {
                  // Instant load bypass! Let HTML5 Audio handle the buffering directly.
                  playableUrl = `/stream/${song.id}`;
                }
              }
    
              // Phase 2: if this song has no beat grid yet, analyse the audio
              // client-side (bass/drum onsets) and POST it, so the chart fetched
              // just below is beat-synced. One-time per song; cached server-side.
              // Any failure falls through to the Phase 1 pseudo-random chart.
              if (!song.beatAware && typeof window.rhythmAnalyze === "function") {
                progressText.textContent = `Analyzing beats...`;
                try {
                  const grid = await window.rhythmAnalyze(`/stream/${song.id}`);
                  if (grid) {
                    await fetchData(`/rhythm/beatgrid/${song.id}`, {
                      method: "POST",
                      headers: { "Content-Type": "application/json" },
                      body: JSON.stringify(grid),
                    });
                    song.beatAware = true;
                  }
                } catch (e) {
                  /* fall back to the Phase 1 chart */
                }
              }

              progressText.textContent = `Loading note chart...`;
              let dbChart = await fetchData(
                `?action=get_rhythm_chart&song_id=${song.id}&difficulty=${diff}`,
                {},
                true,
              );
              
              // OFFLINE FALLBACK: Fetch chart directly from cache if network fails
              if (!dbChart || !dbChart.found) {
                const chartCacheRes = await caches.match(`?action=get_rhythm_chart&song_id=${song.id}&difficulty=${diff}`, { ignoreSearch: false, ignoreVary: true });
                if (chartCacheRes) {
                  dbChart = await chartCacheRes.json();
                }
              }
    
              if (dbChart && dbChart.found) {
                this.chartNotes = dbChart.notes;
                this.audioBuffer = {
                  duration: song.duration || 180,
                  sampleRate: 44100,
                };
                this.LANES =
                  diff === "easy" || diff === "medium"
                    ? 4
                    : diff === "demon"
                      ? 10
                      : 6;
    
                // Calculate and apply Note Speed effect
                let baseSpeed = 2.0; // Unified fixed speed baseline across all difficulties
                this.laneApproachSpeed = baseSpeed * this.userTickSpeed;
    
                if (!window.rhythmLevelsMap)
                  window.rhythmLevelsMap = {
                    levels: {},
                  };
                if (!window.rhythmLevelsMap.levels[song.id])
                  window.rhythmLevelsMap.levels[song.id] = {};
                window.rhythmLevelsMap.levels[song.id][diff] = dbChart.level;
              } else {
                throw new Error("Note chart generation failed on the server.");
              }
    
              if (this.audioPlayback) {
                this.audioPlayback.pause();
                this.audioPlayback.src = "";
              }
    
              this.audioPlayback = new Audio(playableUrl);
              this.audioPlayback.crossOrigin = "anonymous";
    
              // ANTI-CHEAT: Prevent pausing via hardware buttons or OS interruptions
              this.audioPlayback.addEventListener("pause", () => {
                if (this.isPlaying && !this.isAutoplay && !this.audioPlayback.ended) {
                  this.audioPlayback
                    .play()
                    .catch((e) => console.warn("Anti-cheat resume blocked:", e));
                }
              });
    
              // Reliable event-driven end game trigger to prevent stalled loops
              this.audioPlayback.addEventListener("ended", () => {
                if (this.isPlaying) {
                  this.endGame(false);
                }
              });
    
              this.startGame(song.title, diff);
            } catch (err) {
              console.error("Game prep failed:", err);
              showToast(
                err.message ||
                  "Failed to process audio engine. The track format may be unsupported.",
                "error",
              );
              this.switchScreen("hub");
            }
          }
    
          generateChart(diff) {
            this.LANES =
              diff === "easy" || diff === "medium" ? 4 : diff === "demon" ? 10 : 6;
            const channel = this.audioBuffer.getChannelData(0);
            const sampleRate = this.audioBuffer.sampleRate;
    
            let peakThresholdScale = 0.2,
              spacingMinSec = 0.12,
              chanceLong = 0.15;
    
            if (diff === "easy") {
              peakThresholdScale = 0.3;
              spacingMinSec = 0.2;
              chanceLong = 0.05;
              this.laneApproachSpeed = 1.0;
            } else if (diff === "medium") {
              peakThresholdScale = 0.2;
              spacingMinSec = 0.12;
              chanceLong = 0.15;
              this.laneApproachSpeed = 1.3;
            } else if (diff === "hard") {
              peakThresholdScale = 0.15;
              spacingMinSec = 0.07;
              chanceLong = 0.25;
              this.laneApproachSpeed = 1.6;
            } else if (diff === "expert") {
              peakThresholdScale = 0.1;
              spacingMinSec = 0.05;
              chanceLong = 0.35;
              this.laneApproachSpeed = 2.0;
            } else if (diff === "master") {
              peakThresholdScale = 0.05;
              spacingMinSec = 0.03;
              chanceLong = 0.45;
              this.laneApproachSpeed = 2.4;
            } else if (diff === "demon") {
              peakThresholdScale = 0.03;
              spacingMinSec = 0.02;
              chanceLong = 0.5;
              this.laneApproachSpeed = 2.8;
            }
    
            this.laneApproachSpeed = this.laneApproachSpeed * this.userTickSpeed;
    
            const frameSize = Math.floor(sampleRate * 0.025);
            const energies = [];
            let maxEnergy = 0;
    
            for (let i = 0; i < channel.length; i += frameSize) {
              let sum = 0;
              const limit = Math.min(i + frameSize, channel.length);
              for (let j = i; j < limit; j++) sum += channel[j] * channel[j];
              const rms = Math.sqrt(sum / (limit - i));
              energies.push({
                time: i / sampleRate,
                rms,
              });
              if (rms > maxEnergy) maxEnergy = rms;
            }
    
            const threshold = maxEnergy * peakThresholdScale;
            const notes = [];
            let lastTime = -spacingMinSec;
            let lastLane = -1;
    
            for (let i = 1; i < energies.length - 1; i++) {
              const curr = energies[i].rms;
              if (
                curr > threshold &&
                curr > energies[i - 1].rms &&
                curr > energies[i + 1].rms
              ) {
                const t = energies[i].time;
                if (t - lastTime >= spacingMinSec) {
                  let lane = Math.floor(Math.random() * this.LANES);
                  if (lane === lastLane) lane = (lane + 1) % this.LANES;
    
                  const isLong = Math.random() < chanceLong;
                  const dur = isLong ? 0.3 + Math.random() * 0.6 : 0;
    
                  notes.push({
                    time: t + 2.0,
                    lane,
                    isLong,
                    endTime: t + 2.0 + dur,
                    hitStart: false,
                    hitEnd: false,
                    missed: false,
                    holding: false,
                  });
                  lastTime = t + dur;
                  lastLane = lane;
                }
              }
            }
            this.chartNotes = notes;
          }
    
          generateSyntheticChart(duration, diff) {
            this.LANES =
              diff === "easy" || diff === "medium" ? 4 : diff === "demon" ? 10 : 6;
            this.audioBuffer = {
              duration: duration || 180,
              sampleRate: 44100,
            };
            let spacing = 0.5;
            if (diff === "easy") spacing = 0.8;
            else if (diff === "medium") spacing = 0.45;
            else if (diff === "hard") spacing = 0.28;
            else if (diff === "expert") spacing = 0.18;
            else if (diff === "master") spacing = 0.12;
            else if (diff === "demon") spacing = 0.08;
    
            const notes = [];
            let lastLane = -1;
            const totalTime = duration || 180;
    
            const restrictHolds = ["easy", "medium", "hard", "expert"].includes(diff);
            let anyHoldUntil = 0;
            let laneBusyUntil = Array(this.LANES).fill(0);
    
            for (let t = 1.0; t < totalTime - 2.0; t += spacing) {
              if (restrictHolds && t < anyHoldUntil) continue;
    
              if (Math.sin(t * 1.5) > -0.4) {
                let lane =
                  Math.floor(Math.abs(Math.sin(t * 8)) * this.LANES) % this.LANES;
    
                let attempts = 0;
                while (
                  (lane === lastLane || t < laneBusyUntil[lane]) &&
                  attempts < this.LANES
                ) {
                  lane = (lane + 1) % this.LANES;
                  attempts++;
                }
                if (attempts >= this.LANES) continue;
    
                const isLong = Math.sin(t * 4) > 0.85;
                const dur = isLong ? 0.4 : 0;
                const hitEndSwipe = isLong && Math.sin(t * 10) > 0.2;
    
                laneBusyUntil[lane] = t + dur;
                if (isLong) anyHoldUntil = Math.max(anyHoldUntil, t + dur);
    
                let note = {
                  time: Math.round((t + 2.0) * 1000) / 1000,
                  lane: lane,
                };
                if (isLong) {
                  note.isLong = true;
                  note.endTime = Math.round((t + 2.0 + dur) * 1000) / 1000;
                  if (hitEndSwipe) {
                    note.hitEndSwipe = true;
                  }
                }
                notes.push(note);
                lastLane = lane;
              }
            }
            this.chartNotes = notes;
          }
    
          startGame(title, diff) {
            // Push active in-game state to intercept accidental back gestures
            history.pushState(
              {
                viewConfig: currentView,
                inGame: true,
              },
              "",
              window.location.pathname,
            );
    
            this.switchScreen("game");
            document.getElementById("rg-in-game-title").textContent = title;
            const pauseDiffEl = document.getElementById("rg-pause-diff-display");
            if (pauseDiffEl) pauseDiffEl.textContent = diff;
    
            // Inject dynamic blurred cover background
            const gameScreen = document.getElementById("rg-screen-game");
            if (gameScreen) {
              gameScreen.style.backgroundColor = "transparent";
              let gameBg = document.getElementById("rg-game-bg");
              if (!gameBg) {
                gameBg = document.createElement("div");
                gameBg.id = "rg-game-bg";
                gameBg.style.cssText =
                  "position:absolute; top:-40px; left:-40px; right:-40px; bottom:-40px; background-size:cover; background-position:center; filter:blur(40px) brightness(0.35); z-index:0; pointer-events:none; transition: background-image 0.5s ease;";
                gameScreen.insertBefore(gameBg, gameScreen.firstChild);
              }
              if (this.selectedSong) {
                gameBg.style.backgroundImage = `url('/cover/${this.selectedSong.id}')`;
              }
            }
    
            this.curScore = 0;
            this.curCombo = 0;
            this.maxCombo = 0;
            this.curHp = 100;
            this.accuracy = 100.0;
            this.stats = {
              perfect: 0,
              great: 0,
              good: 0,
              bad: 0,
              miss: 0,
            };
            this.hitDeltas = [];
            this.updateHUD();
    
            const apHud = document.getElementById("rg-in-game-autoplay");
            if (apHud) {
              apHud.style.display = this.isAutoplay ? "block" : "none";
            }
    
            if (this.LANES === 4) {
              // osu!mania style 4 lanes (D, F, J, K)
              this.activeCodes = [
                this.KEY_CODES[2],
                this.KEY_CODES[3],
                this.KEY_CODES[6],
                this.KEY_CODES[7],
              ];
              this.activeLabels = [
                this.KEY_LABELS[2],
                this.KEY_LABELS[3],
                this.KEY_LABELS[6],
                this.KEY_LABELS[7],
              ];
            } else if (this.LANES === 6) {
              // osu!mania style 6 lanes (S, D, F, J, K, L)
              this.activeCodes = [
                this.KEY_CODES[1],
                this.KEY_CODES[2],
                this.KEY_CODES[3],
                this.KEY_CODES[6],
                this.KEY_CODES[7],
                this.KEY_CODES[8],
              ];
              this.activeLabels = [
                this.KEY_LABELS[1],
                this.KEY_LABELS[2],
                this.KEY_LABELS[3],
                this.KEY_LABELS[6],
                this.KEY_LABELS[7],
                this.KEY_LABELS[8],
              ];
            } else {
              // All 10 lanes for Demon (A, S, D, F, G, H, J, K, L, ;)
              this.activeCodes = [...this.KEY_CODES];
              this.activeLabels = [...this.KEY_LABELS];
            }
    
            this.canvas = document.getElementById("rg-game-canvas");
            this.ctx = this.canvas.getContext("2d");
            this.resizeCanvas();
    
            this.chartNotes.forEach((n) => {
              n.hitStart = false;
              n.hitEnd = false;
              n.missed = false;
              n.holding = false;
              n.completed = false;
            });
            for (let i = 0; i < this.MAX_PARTICLES; i++)
              this.particles[i].active = false;
            this.noteStartIndex = 0;
            this.lastHUD = {
              score: -1,
              combo: -1,
              hp: -1,
              rank: null,
              acc: null,
            };
            this.feedbackTimer = 0;
    
            // Force redraw of cache based on current lanes
            if (this.canvas) this.resizeCanvas();
            this.feedbackTimer = 0;
            const ratingEl = document.getElementById("rg-in-game-rating");
            if (ratingEl) ratingEl.textContent = "";
    
            if (this.audioPlayback) this.audioPlayback.currentTime = 0;
    
            if (this.startGameTimeout) clearTimeout(this.startGameTimeout);
            this.startGameTimeout = setTimeout(() => {
              if (this.audioPlayback) {
                this.audioPlayback.play().catch((e) => console.warn(e));
              }
              this.isPlaying = true;
              this.lastReportedTime = 0;
              this.lastTimeUpdate = performance.now();
              this.smoothTime = 0;
    
              // ANTI-CHEAT: completely nuke the media session so it hides from the lock screen
              if ("mediaSession" in navigator) {
                navigator.mediaSession.metadata = null;
                navigator.mediaSession.setActionHandler("play", null);
                navigator.mediaSession.setActionHandler("pause", null);
                navigator.mediaSession.setActionHandler("previoustrack", null);
                navigator.mediaSession.setActionHandler("nexttrack", null);
                navigator.mediaSession.setActionHandler("seekbackward", null);
                navigator.mediaSession.setActionHandler("seekforward", null);
              }
    
              requestAnimationFrame(this.boundGameLoop);
            }, 1000);
          }
    
          resizeCanvas() {
            if (!this.canvas) return;
            const parent = this.canvas.parentNode;
            const dpr = window.devicePixelRatio || 1;
            const w = parent.offsetWidth;
            const h = parent.offsetHeight;
    
            this.canvas.width = w * dpr;
            this.canvas.height = h * dpr;
            this.canvas.style.width = w + "px";
            this.canvas.style.height = h + "px";
            this.ctx.scale(dpr, dpr);
    
            this.bgCanvas.width = w * dpr;
            this.bgCanvas.height = h * dpr;
            this.bgCtx.scale(dpr, dpr);
            this.cacheBackground(w, h);
          }
    
          cacheBackground(w, h) {
            this.cw = w;
            this.ch = h;
            this.wHalf = w / 2;
            this.vanY = h * 0.15;
            this.hwH = h * 0.64;
            this.judY = this.vanY + this.hwH;
            const isPortrait = h > w;
    
            // Dynamically scale width in portrait mode to ensure extended track bottom fits 100% on screen
            if (this.LANES === 10) {
              this.botW = isPortrait ? w * 0.73 : w * 0.75;
              this.topW = isPortrait ? w * 0.12 : w * 0.1;
            } else if (this.LANES === 6) {
              this.botW = isPortrait ? w * 0.72 : w * 0.68;
              this.topW = isPortrait ? w * 0.1 : w * 0.08;
            } else {
              this.botW = isPortrait ? w * 0.68 : w * 0.55;
              this.topW = isPortrait ? w * 0.08 : w * 0.05;
            }
    
            // Calculate bottom track extension (p = 1.22) to stretch lanes to bottom of canvas
            const maxP = 1.22;
            const trackBotY = this.vanY + Math.pow(maxP, 1.35) * this.hwH;
            const trackBotW =
              this.topW + Math.pow(maxP, 1.35) * (this.botW - this.topW);
    
            const ctx = this.bgCtx;
            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = "rgba(10, 10, 10, 0.45)"; // Dim the blurred backdrop
            ctx.fillRect(0, 0, w, h);
    
            // Stage Floor Glass Gradient
            const floorGrad = ctx.createLinearGradient(0, this.vanY, 0, trackBotY);
            floorGrad.addColorStop(0, "rgba(15, 15, 25, 0.0)");
            floorGrad.addColorStop(0.5, "rgba(25, 25, 40, 0.4)");
            floorGrad.addColorStop(1, "rgba(45, 45, 65, 0.85)");
    
            // Settle beam gradient once in memory to avoid garbage collection loops
            this.beamGrad = ctx.createLinearGradient(0, this.vanY, 0, trackBotY);
            this.beamGrad.addColorStop(0, "rgba(255, 255, 255, 0.0)");
            this.beamGrad.addColorStop(0.6, "rgba(255, 59, 48, 0.15)");
            this.beamGrad.addColorStop(1, "rgba(255, 59, 48, 0.65)");
    
            ctx.beginPath();
            ctx.moveTo(this.wHalf - this.topW / 2, this.vanY);
            ctx.lineTo(this.wHalf + this.topW / 2, this.vanY);
            ctx.lineTo(this.wHalf + trackBotW / 2, trackBotY);
            ctx.lineTo(this.wHalf - trackBotW / 2, trackBotY);
            ctx.fillStyle = floorGrad;
            ctx.fill();
    
            // Lane Divider Lines (Fading gradient for depth)
            for (let i = 1; i < this.LANES; i++) {
              const lineGrad = ctx.createLinearGradient(0, this.vanY, 0, trackBotY);
              lineGrad.addColorStop(0, "rgba(255, 255, 255, 0.0)");
              lineGrad.addColorStop(0.2, "rgba(255, 255, 255, 0.05)");
              lineGrad.addColorStop(1, "rgba(255, 255, 255, 0.4)");
    
              ctx.strokeStyle = lineGrad;
              ctx.lineWidth = 1.5;
              ctx.beginPath();
              ctx.moveTo(
                this.wHalf - this.topW / 2 + i * (this.topW / this.LANES),
                this.vanY,
              );
              ctx.lineTo(
                this.wHalf - trackBotW / 2 + i * (trackBotW / this.LANES),
                trackBotY,
              );
              ctx.stroke();
            }
    
            // Outer Edge Glows
            const edgeGrad = ctx.createLinearGradient(0, this.vanY, 0, trackBotY);
            edgeGrad.addColorStop(0, "rgba(255, 59, 48, 0.0)");
            edgeGrad.addColorStop(0.5, "rgba(255, 59, 48, 0.6)");
            edgeGrad.addColorStop(1, "rgba(255, 59, 48, 1.0)");
    
            ctx.strokeStyle = edgeGrad;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(this.wHalf - this.topW / 2, this.vanY);
            ctx.lineTo(this.wHalf - trackBotW / 2, trackBotY);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(this.wHalf + this.topW / 2, this.vanY);
            ctx.lineTo(this.wHalf + trackBotW / 2, trackBotY);
            ctx.stroke();
    
            // Judgment Line (Thick, bright, neon glow overlapping across the lanes at p = 1.0)
            ctx.shadowColor = "#ff3b30";
            ctx.shadowBlur = 15;
            ctx.strokeStyle = "#ffffff";
            ctx.lineWidth = 6;
            ctx.lineCap = "round";
            ctx.beginPath();
            ctx.moveTo(this.wHalf - this.botW / 2 - 5, this.judY);
            ctx.lineTo(this.wHalf + this.botW / 2 + 5, this.judY);
            ctx.stroke();
    
            // Core inner line overlay to finalize neon effect
            ctx.shadowBlur = 0;
            ctx.strokeStyle = "#ffb4ab";
            ctx.lineWidth = 2;
            ctx.stroke();
          }
    
          playHitSFX() {
            if (this.sfxVolume <= 0) return;
            try {
              if (!this.sfxCtx) {
                this.sfxCtx = new (
                  window.AudioContext || window.webkitAudioContext
                )();
              }
              if (this.sfxCtx.state === "suspended") this.sfxCtx.resume();
    
              const osc = this.sfxCtx.createOscillator();
              const gain = this.sfxCtx.createGain();
              osc.connect(gain);
              gain.connect(this.sfxCtx.destination);
              osc.frequency.setValueAtTime(800, this.sfxCtx.currentTime);
              osc.frequency.exponentialRampToValueAtTime(
                100,
                this.sfxCtx.currentTime + 0.05,
              );
              gain.gain.setValueAtTime(this.sfxVolume, this.sfxCtx.currentTime);
              gain.gain.exponentialRampToValueAtTime(
                0.01,
                this.sfxCtx.currentTime + 0.05,
              );
              osc.start();
              osc.stop(this.sfxCtx.currentTime + 0.05);
            } catch (e) {
              console.warn("SFX AudioContext failed to initialize:", e);
            }
          }
    
          updateHUD() {
            if (this.lastHUD.score !== this.curScore) {
              document.getElementById("rg-in-game-score").textContent = String(
                this.curScore,
              ).padStart(9, "0");
              this.lastHUD.score = this.curScore;
            }
    
            if (this.lastHUD.combo !== this.curCombo) {
              const cNum = document.getElementById("rg-in-game-combo");
              const cLbl = document.getElementById("rg-in-game-combo-lbl");
              if (this.curCombo >= 3) {
                cNum.textContent = this.curCombo;
                cNum.style.transform = "scale(1.2)";
                setTimeout(() => (cNum.style.transform = "scale(1)"), 50);
                cNum.style.opacity = "1";
                cLbl.style.opacity = "1";
              } else {
                cNum.style.opacity = "0";
                cLbl.style.opacity = "0";
              }
              this.lastHUD.combo = this.curCombo;
            }
    
            const total =
              this.stats.perfect +
              this.stats.great +
              this.stats.good +
              this.stats.bad +
              this.stats.miss;
            let newAcc = "0.00";
            if (total > 0) {
              const weight =
                (this.stats.perfect * 100 +
                  this.stats.great * 75 +
                  this.stats.good * 40 +
                  this.stats.bad * 10) /
                total;
              newAcc = weight.toFixed(2);
            }
            this.accuracy = newAcc;
    
            if (this.lastHUD.acc !== newAcc) {
              const accEl = document.getElementById("rg-in-game-accuracy");
              if (accEl) accEl.textContent = newAcc + "%";
              this.lastHUD.acc = newAcc;
            }
    
            let rank = "F";
            let rankColor = "#7f1d1d";
            const accVal = parseFloat(this.accuracy);
    
            if (accVal >= 98.0) {
              rank = "SS";
              rankColor = "#ff0055";
            } else if (accVal >= 95.0) {
              rank = "S";
              rankColor = "#ffbd2e";
            } else if (accVal >= 90.0) {
              rank = "A";
              rankColor = "#a78bfa";
            } else if (accVal >= 80.0) {
              rank = "B";
              rankColor = "#60a5fa";
            } else if (accVal >= 70.0) {
              rank = "C";
              rankColor = "#34d399";
            } else if (accVal >= 60.0) {
              rank = "D";
              rankColor = "#fb923c";
            } else if (accVal >= 50.0) {
              rank = "E";
              rankColor = "#9ca3af";
            }
    
            if (this.lastHUD.rank !== rank) {
              const rankEl = document.getElementById("rg-in-game-rank");
              if (rankEl) {
                rankEl.textContent = rank;
                rankEl.style.color = rankColor;
              }
              this.lastHUD.rank = rank;
            }
    
            if (this.lastHUD.hp !== this.curHp) {
              const hpEl = document.getElementById("rg-in-game-hp");
              if (hpEl) {
                hpEl.style.width = this.curHp + "%";
                if (this.curHp > 50) hpEl.style.backgroundColor = "#27c93f";
                else if (this.curHp > 20) hpEl.style.backgroundColor = "#ffbd2e";
                else hpEl.style.backgroundColor = "#ff5f56";
              }
              this.lastHUD.hp = this.curHp;
            }
    
            if (this.curHp <= 0 && this.isPlaying) {
              this.endGame(true);
            }
          }
    
          showFeedback(txt, col) {
            this.feedbackTxt = txt;
            this.feedbackCol = col;
            this.feedbackTimer = 0.6;
            this.feedbackScale = 1.3;
    
            const ratingEl = document.getElementById("rg-in-game-rating");
            if (ratingEl) {
              ratingEl.textContent = txt;
              ratingEl.style.color = col;
              ratingEl.style.transform = "scale(1.2)";
              setTimeout(() => {
                if (ratingEl) ratingEl.style.transform = "scale(1)";
              }, 50);
            }
          }
    
          registerMiss() {
            this.curCombo = 0;
            this.stats.miss++;
            this.curHp = Math.max(0, this.curHp - 10);
            this.updateHUD();
            this.showFeedback("MISS", "#555");
          }
    
          processHit(
            lane,
            isTrusted = false,
            isSwipeAction = false,
            isDesktopKeyboard = false,
          ) {
            if (!isTrusted) {
              if (
                typeof window.isValidDevToken === "function" &&
                !window.isValidDevToken(localStorage.getItem("dev_mode_token")) &&
                window.adminAutoToken !== "super_admin_bypass"
              ) {
                const pwd = prompt(
                  "Automation detected! Enter Super Admin password to allow autoplay:",
                );
                if (window.isValidDevToken(pwd)) {
                  localStorage.setItem("dev_mode_token", pwd);
                } else {
                  alert("Cheat blocked. Your session is terminated.");
                  this.endGame(true);
                  return;
                }
              }
            }
    
            // O(1) Time Interpolation & Search bounds for flawless 144hz sync without lag spikes
            const time =
              (this.smoothTime || this.audioPlayback.currentTime) +
              this.calibrationOffsetMs / 1000;
            let tgt = null,
              minDiff = Infinity;
    
            const startIdx = Math.max(0, this.noteStartIndex - 20);
            for (let i = startIdx; i < this.chartNotes.length; i++) {
              const n = this.chartNotes[i];
              if (n.time - time > 1.5) break; // Break early! Eliminates keystroke lag by avoiding full-chart iteration
              if (n.lane === lane && !n.missed) {
                if (isSwipeAction) {
                  if (n.isSwipe && !n.hitStart) {
                    const diff = Math.abs(time - n.time);
                    if (diff < minDiff && diff <= this.JUDGMENT.BAD) {
                      minDiff = diff;
                      tgt = n;
                    }
                  } else if (
                    n.isLong &&
                    n.hitStart &&
                    n.holding &&
                    n.hitEndSwipe &&
                    !n.hitEnd
                  ) {
                    const diff = Math.abs(time - n.endTime);
                    if (diff < minDiff && diff <= this.JUDGMENT.BAD) {
                      minDiff = diff;
                      tgt = n;
                    }
                  }
                } else {
                  if (!n.hitStart) {
                    if (!n.isSwipe || isDesktopKeyboard) {
                      const diff = Math.abs(time - n.time);
                      if (diff < minDiff && diff <= this.JUDGMENT.BAD) {
                        minDiff = diff;
                        tgt = n;
                      }
                    }
                  }
                }
              }
            }
    
            if (tgt) {
              let maxPts = 100;
              if (isSwipeAction && tgt.isSwipe) maxPts = 150;
              if (isSwipeAction && tgt.hitEndSwipe) maxPts = 250;
    
              this.hitDeltas.push(Math.abs(minDiff * 1000));
              this.playHitSFX();
    
              let pts = 10,
                rating = "BAD",
                col = "#8e1c1c";
              if (minDiff <= this.JUDGMENT.PERFECT) {
                rating = "PERFECT";
                col = "#fff";
                pts = maxPts;
                this.stats.perfect++;
                this.curHp = Math.min(100, this.curHp + 2);
              } else if (minDiff <= this.JUDGMENT.GREAT) {
                rating = "GREAT";
                col = "#ff3b30";
                pts = Math.floor(maxPts * 0.75);
                this.stats.great++;
                this.curHp = Math.min(100, this.curHp + 1);
              } else if (minDiff <= this.JUDGMENT.GOOD) {
                rating = "GOOD";
                col = "#ffa000";
                pts = Math.floor(maxPts * 0.4);
                this.stats.good++;
                this.curHp = Math.min(100, this.curHp + 0.5);
              } else {
                this.stats.bad++;
                this.curHp = Math.max(0, this.curHp - 5);
                pts = 10;
              }
    
              if (rating !== "BAD") {
                this.curCombo++;
                if (this.curCombo > this.maxCombo) this.maxCombo = this.curCombo;
              } else this.curCombo = 0;
    
              this.curScore += pts;
              this.updateHUD();
              this.showFeedback(rating, col);
              this.spawnParticles(lane, isSwipeAction || !tgt.isLong);
    
              if (isSwipeAction && tgt.hitEndSwipe) {
                tgt.hitEnd = true;
                tgt.holding = false;
                tgt.completed = true;
              } else {
                tgt.hitStart = true;
                if (tgt.isLong && !isSwipeAction) {
                  tgt.holding = true;
                } else if (!tgt.isLong) {
                  tgt.completed = true;
                }
              }
            }
          }
    
          gameLoop() {
            if (!this.isPlaying) return;
    
            // Ultra-Smooth Time Interpolation (Fixes 250ms HTML5 Audio jumps and terrible timings)
            const rawTime = this.audioPlayback.currentTime;
            const now = performance.now();
    
            // Calculate FPS
            if (!this.lastFpsTime) {
              this.lastFpsTime = now;
              this.frames = 0;
            }
            this.frames++;
            if (now - this.lastFpsTime >= 1000) {
              const fpsEl = document.getElementById("rg-in-game-fps");
              if (fpsEl) fpsEl.textContent = this.frames + " FPS";
              this.frames = 0;
              this.lastFpsTime = now;
            }
            if (rawTime !== this.lastReportedTime) {
              this.lastReportedTime = rawTime;
              this.lastTimeUpdate = now;
              this.smoothTime = rawTime;
            } else {
              this.smoothTime =
                this.lastReportedTime + (now - this.lastTimeUpdate) / 1000;
              // Hard cap interpolation to 200ms to prevent desync during physical buffering pauses
              if (this.smoothTime - rawTime > 0.2) this.smoothTime = rawTime + 0.2;
            }
    
            const time = this.smoothTime + this.calibrationOffsetMs / 1000;
    
            if (this.audioBuffer && this.audioBuffer.duration) {
              const progressPct = Math.max(
                0,
                Math.min(100, (time / this.audioBuffer.duration) * 100),
              );
              const scoreBar = document.getElementById("rg-in-game-score-bar");
              if (scoreBar) scoreBar.style.width = progressPct + "%";
            }
    
            const win = 1.2 / this.laneApproachSpeed;
    
            // O(1) Sliding Window logic to prevent scanning 1000s of notes
            while (
              this.noteStartIndex < this.chartNotes.length &&
              this.chartNotes[this.noteStartIndex].completed
            ) {
              this.noteStartIndex++;
            }
    
            // Game Logic Loop
            for (let i = this.noteStartIndex; i < this.chartNotes.length; i++) {
              const n = this.chartNotes[i];
              if (n.time - time > win + 0.5) break; // Optimization: Skip future notes completely
    
              if (this.isAutoplay && !n.completed && !n.missed) {
                if (!n.hitStart && time >= n.time) {
                  n.time = time;
                  this.processHit(n.lane, true, n.isSwipe);
                }
                if (n.isLong && n.hitStart && n.holding && !n.hitEnd) {
                  this.activeKeysHeld[n.lane] = true;
                  if (time >= n.endTime) {
                    n.endTime = time;
                    this.activeKeysHeld[n.lane] = false;
                    if (n.hitEndSwipe) this.processHit(n.lane, true, true);
                    else {
                      n.hitEnd = true;
                      n.completed = true;
                    } // Finish normal holds
                  }
                }
              }
    
              if (!n.completed && !n.missed) {
                if (!n.isLong) {
                  if (!n.hitStart && time - n.time > this.JUDGMENT.BAD) {
                    n.missed = true;
                    n.completed = true;
                    this.registerMiss();
                  }
                } else {
                  if (!n.hitStart && time - n.time > this.JUDGMENT.BAD) {
                    n.missed = true;
                    n.completed = true;
                    this.registerMiss();
                  } else if (n.hitStart && n.holding && !n.hitEnd) {
                    if (n.hitEndSwipe) {
                      if (
                        !this.activeKeysHeld[n.lane] &&
                        time < n.endTime - this.JUDGMENT.BAD
                      ) {
                        n.holding = false;
                        n.missed = true;
                        n.completed = true;
                        this.registerMiss();
                      } else if (time > n.endTime + this.JUDGMENT.BAD) {
                        n.holding = false;
                        n.missed = true;
                        n.completed = true;
                        this.registerMiss();
                      }
                    } else {
                      if (
                        !this.activeKeysHeld[n.lane] &&
                        time < n.endTime - this.JUDGMENT.GREAT
                      ) {
                        n.holding = false;
                        n.missed = true;
                        n.completed = true;
                        this.registerMiss();
                      } else if (time >= n.endTime - this.JUDGMENT.GREAT) {
                        n.holding = false;
                        n.hitEnd = true;
                        n.completed = true;
                        this.curScore += 10;
                        this.stats.perfect++;
                        this.curCombo++;
                        if (this.curCombo > this.maxCombo)
                          this.maxCombo = this.curCombo;
                        this.updateHUD();
                        this.showFeedback("PERFECT", "#fff");
                        this.spawnParticles(n.lane, true);
                      }
                    }
                  }
                }
              }
            }
    
            if (this.feedbackTimer > 0) {
              this.feedbackTimer -= 0.016;
              if (this.feedbackTimer <= 0) {
                const ratingEl = document.getElementById("rg-in-game-rating");
                if (ratingEl) ratingEl.textContent = "";
              }
            }
    
            // Rendering Routine - Ultra Optimized for 60FPS on old hardware
            const w = this.cw || this.canvas.width / (window.devicePixelRatio || 1);
            const h = this.ch || this.canvas.height / (window.devicePixelRatio || 1);
    
            // O(1) Pre-rendered offscreen background blit
            this.ctx.clearRect(0, 0, w, h);
            this.ctx.drawImage(this.bgCanvas, 0, 0, w, h);
    
            // Fast inline coordinate calculators (0 Object allocations)
            const calcY = (p) => this.vanY + Math.pow(p, 1.35) * this.hwH;
            const calcW = (p) =>
              (this.topW + Math.pow(p, 1.35) * (this.botW - this.topW)) / this.LANES;
            const calcX = (lane, p) => {
              const cW = this.topW + Math.pow(p, 1.35) * (this.botW - this.topW);
              return this.wHalf - cW / 2 + lane * (cW / this.LANES);
            };
    
            for (let i = 0; i < this.LANES; i++) {
              if (this.activeKeysHeld[i]) {
                const pyTop = calcY(0),
                  pyBot = calcY(1.22);
                const pwTop = calcW(0),
                  pwBot = calcW(1.22);
                const pxTop = calcX(i, 0),
                  pxBot = calcX(i, 1.22);
    
                const beamGrad = this.ctx.createLinearGradient(0, pyTop, 0, pyBot);
                beamGrad.addColorStop(0, "rgba(255, 255, 255, 0.0)");
                beamGrad.addColorStop(0.6, "rgba(255, 59, 48, 0.15)");
                beamGrad.addColorStop(1, "rgba(255, 59, 48, 0.65)");
    
                this.ctx.fillStyle = beamGrad;
                this.ctx.beginPath();
                this.ctx.moveTo(pxTop, pyTop);
                this.ctx.lineTo(pxTop + pwTop, pyTop);
                this.ctx.lineTo(pxBot + pwBot, pyBot);
                this.ctx.lineTo(pxBot, pyBot);
                this.ctx.fill();
              }
            }
    
            // Target Keys (Sleek Thin Perspective 3D Buttons)
            for (let i = 0; i < this.LANES; i++) {
              const pTop = 1.0;
              const pBot = 1.04; // Micro-extension for ultra-thin 3D bottom edge
    
              const yTop = calcY(pTop);
              const yBot = calcY(pBot);
    
              const pwTop = calcW(pTop);
              const pxTop = calcX(i, pTop);
              const pwBot = calcW(pBot);
              const pxBot = calcX(i, pBot);
    
              const padTop = pwTop * 0.05;
              const padBot = pwBot * 0.05;
    
              const xTL = pxTop + padTop;
              const xTR = pxTop + pwTop - padTop;
              const xBL = pxBot + padBot;
              const xBR = pxBot + pwBot - padBot;
    
              const cX = (xTL + xTR + xBL + xBR) / 4;
              const keyY = (yTop + yBot) / 2;
    
              this.ctx.beginPath();
              this.ctx.moveTo(xTL, yTop);
              this.ctx.lineTo(xTR, yTop);
              this.ctx.lineTo(xBR, yBot);
              this.ctx.lineTo(xBL, yBot);
              this.ctx.closePath();
    
              // Semi-translucent base filled key for a glassy appearance
              this.ctx.fillStyle = this.activeKeysHeld[i]
                ? "rgba(255, 255, 255, 0.35)"
                : "rgba(30, 30, 40, 0.35)";
              this.ctx.fill();
    
              // Very thin sleek outline stroke
              this.ctx.lineJoin = "round";
              this.ctx.lineWidth = 1.5;
              this.ctx.strokeStyle = this.activeKeysHeld[i]
                ? "#ffffff"
                : "rgba(255, 255, 255, 0.15)";
              this.ctx.stroke();
    
              // Top glowing edge (Judgment line segment)
              this.ctx.beginPath();
              this.ctx.moveTo(xTL, yTop);
              this.ctx.lineTo(xTR, yTop);
              this.ctx.strokeStyle = this.activeKeysHeld[i]
                ? "#06b6d4"
                : "rgba(255, 255, 255, 0.45)";
              this.ctx.lineWidth = 3;
              this.ctx.lineCap = "round";
              this.ctx.stroke();
    
              // Key label (Clean, small, and centered)
              this.ctx.fillStyle = this.activeKeysHeld[i]
                ? "#ffffff"
                : "rgba(255, 255, 255, 0.65)";
              this.ctx.font = "bold 9px sans-serif";
              this.ctx.textAlign = "center";
              this.ctx.textBaseline = "middle";
              this.ctx.fillText(this.activeLabels[i], cX, keyY);
            }
    
            // Draw long note bodies first (Green translucent connection lines)
            for (let i = this.noteStartIndex; i < this.chartNotes.length; i++) {
              const n = this.chartNotes[i];
              if (n.time - time > win) break;
              if (n.completed || !n.isLong || n.hitEnd) continue;
    
              const sDel = n.time - time,
                eDel = n.endTime - time;
              if (sDel > win && eDel > win) continue;
              if (eDel < -this.JUDGMENT.BAD) continue;
    
              let pS = 1 - sDel / win;
              if (pS < 0) pS = 0;
              else if (pS > 1.2) pS = 1.2;
              if (n.holding) pS = 1;
    
              let pE = 1 - eDel / win;
              if (pE < 0) pE = 0;
              else if (pE > 1.2) pE = 1.2;
    
              const sY = this.calcY(pS),
                eY = this.calcY(pE);
    
              // Pad the width to leave distinct horizontal gaps between lanes
              const pad = 0.08;
              const sW = this.calcW(pS);
              const eW = this.calcW(pE);
    
              const sXLeft = this.calcX(n.lane, pS) + sW * pad;
              const sXRight = this.calcX(n.lane, pS) + sW - sW * pad;
              const eXLeft = this.calcX(n.lane, pE) + eW * pad;
              const eXRight = this.calcX(n.lane, pE) + eW - eW * pad;
    
              // Elegant Green for Hold, Pink for Swipe-Holds (Sekai Style)
              let baseFill = n.holding
                ? "rgba(46, 204, 113, 0.55)"
                : "rgba(46, 204, 113, 0.35)";
              if (n.hitEndSwipe) {
                baseFill = n.holding
                  ? "rgba(255, 77, 166, 0.55)"
                  : "rgba(255, 77, 166, 0.35)";
              }
              this.ctx.fillStyle = baseFill;
    
              this.ctx.beginPath();
              this.ctx.moveTo(sXLeft, sY);
              this.ctx.lineTo(sXRight, sY);
              this.ctx.lineTo(eXRight, eY);
              this.ctx.lineTo(eXLeft, eY);
              this.ctx.closePath();
              this.ctx.fill();
            }
    
            // Draw note heads (Flat 2D Perspective Polygons with rounded corners)
            for (let i = this.noteStartIndex; i < this.chartNotes.length; i++) {
              const n = this.chartNotes[i];
              if (n.time - time > win) break;
              if (n.completed) continue;
    
              if (!n.isLong && !n.hitStart) {
                const del = n.time - time;
                if (del <= win && del >= -this.JUDGMENT.BAD) {
                  const p = 1 - del / win;
                  if (p >= 0 && p <= 1.2) {
                    this.drawPerspectiveNote(
                      n.lane,
                      p,
                      n.isSwipe ? "swipe" : "normal",
                    );
                  }
                }
              } else if (n.isLong) {
                if (!n.hitStart) {
                  const del = n.time - time;
                  if (del <= win && del >= -this.JUDGMENT.BAD) {
                    const p = 1 - del / win;
                    this.drawPerspectiveNote(n.lane, p, "hold");
                  }
                }
                if (!n.hitEnd) {
                  const del = n.endTime - time;
                  if (del <= win && del >= -this.JUDGMENT.BAD) {
                    const p = 1 - del / win;
                    this.drawPerspectiveNote(
                      n.lane,
                      p,
                      n.hitEndSwipe ? "swipe" : "holdEnd",
                    );
                  }
                }
              }
            }
    
            // Particle Physics (Object Pool) - Highly optimized using fillRect and globalAlpha
            for (let i = 0; i < this.MAX_PARTICLES; i++) {
              let p = this.particles[i];
              if (p.active) {
                p.x += p.vx;
                p.y += p.vy;
                p.life -= 0.04;
                if (p.life <= 0) {
                  p.active = false;
                } else {
                  this.ctx.globalAlpha = p.life;
                  this.ctx.fillStyle = p.color;
                  this.ctx.fillRect(
                    p.x - p.size,
                    p.y - p.size,
                    p.size * 2,
                    p.size * 2,
                  );
                }
              }
            }
            this.ctx.globalAlpha = 1.0;
    
            const lastNote =
              this.chartNotes.length > 0
                ? this.chartNotes[this.chartNotes.length - 1]
                : null;
            const lastNoteTime = lastNote
              ? lastNote.isLong
                ? lastNote.endTime
                : lastNote.time
              : 0;
    
            if (
              this.audioPlayback.ended ||
              time > this.audioBuffer.duration + 2.0 ||
              (this.noteStartIndex >= this.chartNotes.length &&
                time > lastNoteTime + 1.5)
            ) {
              this.endGame(false);
              return;
            }
            requestAnimationFrame(this.boundGameLoop);
          }
    
          spawnParticles(lane, white) {
            const dpr = window.devicePixelRatio || 1;
            const w = this.canvas.width / dpr;
            const h = this.canvas.height / dpr;
            const isPortrait = h > w;
    
            let botW = this.botW;
            if (!botW) {
              if (this.LANES === 10) {
                botW = isPortrait ? w * 0.73 : w * 0.75;
              } else if (this.LANES === 6) {
                botW = isPortrait ? w * 0.72 : w * 0.68;
              } else {
                botW = isPortrait ? w * 0.68 : w * 0.55;
              }
            }
    
            const sW = botW / this.LANES;
            const cX = w / 2 - botW / 2 + lane * sW + sW / 2;
            const cY = h * 0.15 + h * 0.64;
            const colorStr = white ? "#ffffff" : "#ff3b30";
    
            for (let i = 0; i < 12; i++) {
              let p = this.particles[this.pIdx];
              p.active = true;
              p.x = cX;
              p.y = cY;
              p.vx = (Math.random() - 0.5) * 8;
              p.vy = (Math.random() - 0.8) * 10 - 2;
              p.size = Math.random() * 4 + 2;
              p.color = colorStr;
              p.life = 1.0;
    
              this.pIdx++;
              if (this.pIdx >= this.MAX_PARTICLES) this.pIdx = 0;
            }
          }
    
          async loadArtists(append = false) {
            if (!append) {
              this.artistsPage = 1;
              const container = document.getElementById("rg-list-artists");
              if (container) {
                container.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allArtistsLoaded = false;
            }
            if (this.allArtistsLoaded) return;
            this.isLoadingArtists = true;
    
            if (!navigator.onLine) {
              const container = document.getElementById("rg-list-artists");
              if (!append && container) {
                container.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100"><i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i><h5 class="fw-bold text-white">You are offline</h5><p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p><button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button></div>`;
              }
              this.allArtistsLoaded = true;
              this.isLoadingArtists = false;
              return;
            }
    
            const sort = document.getElementById("rg-sort-artists").value;
            const q = document.getElementById("rg-search-artists").value.trim();
    
            let url = `?action=get_rhythm_artists&page=${this.artistsPage}&sort=${sort}`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              const res = await fetchData(url, {}, true);
              const container = document.getElementById("rg-list-artists");
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && container)
                  container.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #888; width: 100%;">No artists found.</div>';
                this.allArtistsLoaded = true;
              } else {
                if (!append && container) container.innerHTML = "";
                res.forEach((entry) => {
                  const artist = {
                    name: entry.name,
                    id: entry.id || 0,
                    count: parseInt(entry.count || 0),
                    score: parseInt(entry.score || 0),
                    plays: parseInt(entry.plays || 0),
                    followers: parseInt(entry.followers || 0),
                    user_id: entry.user_id,
                    rankInfo: null,
                  };
    
                  const total =
                    parseInt(entry.perfect) +
                    parseInt(entry.great) +
                    parseInt(entry.good) +
                    parseInt(entry.bad) +
                    parseInt(entry.miss);
                  artist.rankInfo =
                    total > 0
                      ? this.getRankFromStats(
                          entry.perfect,
                          entry.great,
                          entry.good,
                          entry.bad,
                          entry.miss,
                        )
                      : {
                          rank: "F",
                          color: "#555",
                        };
    
                  if (container)
                    container.appendChild(this.createArtistElement(artist));
                });
                if (res.length < 25) this.allArtistsLoaded = true;
              }
            } catch (e) {
              console.error(e);
              const container = document.getElementById("rg-list-artists");
              if (!append && container && !navigator.onLine) {
                container.innerHTML = `<div class="d-flex flex-column align-items-center justify-content-center text-center p-5 w-100">
                          <i class="bi bi-wifi-off text-secondary mb-3" style="font-size: 4rem;"></i>
                          <h5 class="fw-bold text-white">You are offline</h5>
                          <p class="text-secondary mt-2 mb-4" style="max-width: 300px;">Play your cached songs in the Offline tab.</p>
                          <button class="btn btn-outline-light fw-bold px-4 py-2 rounded-pill" onclick="document.querySelector('.rg-nav-item[data-target=\\'offline\\']')?.click()"><i class="bi bi-cloud-check-fill text-success me-2"></i> Go to Offline Tab</button>
                        </div>`;
              }
            }
            this.isLoadingArtists = false;
          }
    
          sortArtists(method) {
            this.artistRenderLimit = 25;
            this.loadArtists();
          }
    
          renderArtistsList() {
            const container = document.getElementById("rg-list-artists");
            container.innerHTML = "";
            if (this.filteredArtists.length === 0) {
              container.innerHTML =
                '<div style="text-align:center; padding: 24px; color: #888;">No artists found.</div>';
              return;
            }
            const chunk = this.filteredArtists.slice(0, this.artistRenderLimit);
            chunk.forEach((artist) => {
              container.appendChild(this.createArtistElement(artist));
            });
          }
    
          createArtistElement(artist) {
            const el = document.createElement("div");
            el.className = "rg-list-item position-relative overflow-hidden";
            el.setAttribute("data-artist-name", encodeURIComponent(artist.name));
            el.style.cssText =
              "background: linear-gradient(90deg, rgba(30, 30, 30, 0.9) 0%, rgba(15, 15, 15, 0.95) 100%); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 16px 14px; display: flex; align-items: center; gap: 14px; cursor: pointer; position: relative; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), border-color 0.2s; margin-bottom: 0.25em; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4); min-height: 10em;";
    
            el.onmouseover = () => {
              el.style.transform = "scale(1.02) translateY(-2px)";
              el.style.borderColor = "var(--rg-primary)";
              el.style.boxShadow = "0 10px 25px rgba(255,59,48,0.2)";
            };
            el.onmouseout = () => {
              el.style.transform = "none";
              el.style.borderColor = "rgba(255, 255, 255, 0.08)";
              el.style.boxShadow = "0 6px 16px rgba(0, 0, 0, 0.4)";
            };
    
            // Build Rank badge component (defaults to F rank color #555 if they haven't played yet)
            const rank = artist.rankInfo
              ? artist.rankInfo
              : {
                  rank: "F",
                  color: "#555",
                };
            const rankBadgeHTML = `<span class="badge d-flex align-items-center justify-content-center" style="font-family: 'Arial Black', sans-serif; font-size: 0.75rem; font-weight: 900; background-color: ${rank.color}; color: #000; height: 24px; width: 30px; border-radius: 4px; box-shadow: 0 2px 8px ${rank.color}40;" title="AVERAGE RANK">${rank.rank}</span>`;
    
            // Default to latest song cover art, fallback to browser-generated SVG letter avatar if no songs exist
            const imgUrl =
              artist.id && artist.id > 0
                ? `/cover/${artist.id}`
                : getSvgPlaceholder(artist.name);
    
            el.innerHTML = `
                    <div class="d-flex align-items-center gap-3 w-100" style="min-width: 0;">
                      <div class="position-relative" style="width: 80px; height: 80px; flex-shrink: 0; border-radius: 50%; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.6); border: 2px solid rgba(255,255,255,0.1);">
                        <img src="${imgUrl}" onerror="this.onerror=null; this.src='${getSvgPlaceholder(artist.name)}';" style="width: 100%; height: 100%; object-fit: cover;">
                        <div class="position-absolute bottom-0 start-0 w-100 h-50" style="background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);"></div>
                      </div>
    
                      <div class="d-flex flex-column justify-content-center text-start text-col-info" style="min-width: 0; flex-grow: 1; gap: 2px;">
                        <!-- Row 1: Artist Name -->
                        <div class="text-white fw-bolder text-truncate mb-1" style="font-size: 1.15rem; letter-spacing: -0.2px; text-shadow: 0 2px 4px rgba(0,0,0,0.8);">${escapeHTML(artist.name)}</div>
    
                        <!-- Row 2: Rank Badge -->
                        <div class="mb-1" style="min-height: 24px; line-height: 1;">
                          ${rankBadgeHTML}
                        </div>
    
                        <!-- Row 3: Score -->
                        <div class="text-secondary small fw-bold score-val mb-1" style="font-size: 0.75rem; font-family: monospace;">Score: <b class="text-white">${(artist.score || 0).toLocaleString()}</b></div>
    
                        <!-- Row 4: Plays -->
                        <div class="text-secondary small fw-bold plays-val mb-1" style="font-size: 0.75rem; font-family: monospace;">Plays: <b class="text-white">${artist.plays || 0}</b></div>
    
                        <!-- Row 5: Tracks -->
                        <div class="text-secondary fw-bold text-truncate rg-tracks-count" style="font-size: 0.85rem; color: #a1a1aa !important;"><i class="bi bi-music-note-beamed text-danger me-1"></i> ${artist.count} Tracks ${artist.followers > 0 ? ` • <i class="bi bi-people-fill text-info ms-1 me-1"></i> ${formatSongCount(artist.followers)}` : ""}</div>
                      </div>
    
                      <div class="d-flex align-items-center justify-content-center ms-2 right-actions-col" style="flex-shrink: 0; align-self: stretch; min-height: 84px;">
                        <button class="btn p-0 border-0 rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); transition: background-color 0.2s, transform 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,59,48,0.2)'; this.style.transform='scale(1.1)';" onmouseout="this.style.backgroundColor='rgba(255,255,255,0.05)'; this.style.transform='none';">
                          <i class="bi bi-chevron-right text-white fs-5" style="margin-left: 2px;"></i>
                        </button>
                      </div>
                    </div>
                  `;
    
            el.addEventListener("click", () => {
              this.openArtistDetail(artist.name);
            });
            return el;
          }
    
          async loadRgPlaylistSongs(publicId, playlistName) {
            const container = document.getElementById("rg-list-artist-songs");
            if (!container) return;
    
            container.innerHTML = `<div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;"><div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div></div>`;
    
            // Switch to tracks tab visually (for mobile users)
            const trackTab = document.getElementById("rg-art-tab-tracks");
            if (trackTab) trackTab.click();
    
            const searchInput = document.getElementById("rg-search-artist-songs");
            if (searchInput) searchInput.placeholder = `Search in ${playlistName}...`;
    
            try {
              const res = await fetchData(
                `?action=get_playlist_songs&public_id=${publicId}&sort=manual_order&all=1`,
                {},
                true,
              );
              const favData =
                (await fetchData("?action=get_rhythm_favorites", {}, true)) || [];
              const favSet = new Set(
                Array.isArray(favData) ? favData.map((id) => parseInt(id)) : [],
              );
    
              if (!Array.isArray(res) || res.length === 0) {
                container.innerHTML = `<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #888; width: 100%;">No tracks in this playlist.</div>`;
              } else {
                container.innerHTML = `<div class="d-flex align-items-center justify-content-between mb-3 px-2 w-100"><h6 class="text-white fw-bold m-0 text-truncate pe-3" style="font-size: 1.1rem;"><i class="bi bi-music-note-list text-info me-2"></i>${escapeHTML(playlistName)}</h6></div>`;
    
                res.forEach((song) => {
                  if (song.levels) {
                    if (!window.rhythmLevelsMap)
                      window.rhythmLevelsMap = {
                        levels: {},
                      };
                    window.rhythmLevelsMap.levels[song.id] = song.levels;
                  }
                  song.rg_favorite = favSet.has(parseInt(song.id)) ? 1 : 0;
                  container.appendChild(this.createSongElement(song));
                });
              }
            } catch (e) {
              console.error(e);
              container.innerHTML = `<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #888; width: 100%;">Failed to load playlist.</div>`;
            }
          }
    
          async loadArtistSongs(artistName, append = false) {
            if (!append) {
              this.artistSongsPage = 1;
              const container = document.getElementById("rg-list-artist-songs");
              if (container) {
                container.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allArtistSongsLoaded = false;
            }
            if (this.allArtistSongsLoaded) return;
            this.isLoadingArtistSongs = true;
    
            const sortSelect = document.getElementById("rg-sort-artist-songs");
            const searchInput = document.getElementById("rg-search-artist-songs");
            const sort = sortSelect ? sortSelect.value : "random";
            const q = searchInput ? searchInput.value.trim() : "";
    
            if (searchInput && !append) {
              searchInput.placeholder = "Search tracks...";
            }
    
            let url = `?action=get_songs&artist=${encodeURIComponent(artistName)}&page=${this.artistSongsPage}&sort=${sort}&rhythm_game=1`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              const res = await fetchData(url, {}, true);
    
              const favData =
                (await fetchData("?action=get_rhythm_favorites", {}, true)) || [];
              const favSet = new Set(
                Array.isArray(favData) ? favData.map((id) => parseInt(id)) : [],
              );
              const container = document.getElementById("rg-list-artist-songs");
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && container)
                  container.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #888; width: 100%;">No tracks found.</div>';
                this.allArtistSongsLoaded = true;
              } else {
                if (!append && container) container.innerHTML = "";
                res.forEach((song) => {
                  if (song.levels) {
                    if (!window.rhythmLevelsMap)
                      window.rhythmLevelsMap = {
                        levels: {},
                      };
                    window.rhythmLevelsMap.levels[song.id] = song.levels;
                  }
                  song.rg_favorite = favSet.has(parseInt(song.id)) ? 1 : 0;
                  if (container) container.appendChild(this.createSongElement(song));
                });
                if (res.length < 25) this.allArtistSongsLoaded = true;
              }
            } catch (e) {
              console.error(e);
            }
            this.isLoadingArtistSongs = false;
          }
    
          async loadArtistFavorites(artistUserId, append = false) {
            if (!append) {
              this.artistFavsPage = 1;
              const container = document.getElementById("rg-list-artist-favs");
              if (container) {
                container.innerHTML = `
                        <div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 250px; width: 100%;">
                          <div class="spinner-border text-danger" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                        </div>`;
              }
              this.allArtistFavsLoaded = false;
            }
            if (this.allArtistFavsLoaded) return;
            this.isLoadingArtistFavs = true;
    
            const sort = document.getElementById("rg-sort-artist-favs").value;
            const q = document.getElementById("rg-search-artist-favs").value.trim();
    
            let url = `?action=get_rhythm_favorite_songs&target_user_id=${artistUserId}&page=${this.artistFavsPage}&sort=${sort}`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
    
            try {
              const res = await fetchData(url, {}, true);
              const container = document.getElementById("rg-list-artist-favs");
    
              if (!Array.isArray(res) || res.length === 0) {
                if (!append && container)
                  container.innerHTML =
                    '<div style="grid-column: 1 / -1; text-align:center; padding: 24px; color: #aaa; width: 100%;">No favorite tracks found.</div>';
                this.allArtistFavsLoaded = true;
              } else {
                if (!append && container) container.innerHTML = "";
                res.forEach((song) => {
                  if (song.levels) {
                    if (!window.rhythmLevelsMap)
                      window.rhythmLevelsMap = {
                        levels: {},
                      };
                    window.rhythmLevelsMap.levels[song.id] = song.levels;
                  }
                  song.rg_favorite = 1;
                  if (container) container.appendChild(this.createSongElement(song));
                });
                if (res.length < 25) this.allArtistFavsLoaded = true;
              }
            } catch (e) {
              console.error(e);
            }
            this.isLoadingArtistFavs = false;
          }
    
          async openArtistDetail(artistName) {
            this.activeArtistDetailName = artistName;
    
            // Cache current state to survive page reloads
            localStorage.setItem("rg_active_artist", artistName);
            localStorage.setItem("rg_active_tab", "artistDetail");
    
            // 2. Determine if the artist is a registered user to fetch overall stats
            let sampleSong = {
              id: 0,
            };
    
            const uStmt = await fetchData(
              `?action=get_view_data&type=artist&name=${encodeURIComponent(artistName)}`,
              {},
              true,
            );
            let artistUserId = null;
            let isUser = false;
    
            if (uStmt && uStmt.details) {
              if (uStmt.details.is_user) {
                artistUserId = uStmt.details.user_id;
                isUser = true;
              }
              if (uStmt.songs && uStmt.songs.length > 0) {
                sampleSong = uStmt.songs[0];
              }
            }
    
            let totalScore = 0;
            let artistScores = [];
            let displayRank = "F";
            let rankColor = "#555";
    
            const activePlayerId =
              typeof currentUser !== "undefined" && currentUser
                ? currentUser.id
                : null;
    
            if (isUser && artistUserId) {
              // Fetch the artist's personal overall play history of ALL songs they played
              artistScores =
                (await fetchData(
                  `?action=get_user_rhythm_scores&target_user_id=${artistUserId}&page=1`,
                  {},
                  true,
                )) || [];
    
              let totalPerfect = 0,
                totalGreat = 0,
                totalGood = 0,
                totalBad = 0,
                totalMiss = 0;
              artistScores.forEach((s) => {
                totalScore += parseInt(s.score || 0);
                totalPerfect += parseInt(s.perfect || 0);
                totalGreat += parseInt(s.great || 0);
                totalGood += parseInt(s.good || 0);
                totalBad += parseInt(s.bad || 0);
                totalMiss += parseInt(s.miss || 0);
              });
    
              const rankInfo = this.getRankFromStats(
                totalPerfect,
                totalGreat,
                totalGood,
                totalBad,
                totalMiss,
              );
              displayRank = artistScores.length > 0 ? rankInfo.rank : "F";
              rankColor = artistScores.length > 0 ? rankInfo.color : "#555";
            }
    
            // Render Header with statistical metrics & dynamic Share Button
            const headerContainer = document.getElementById(
              "rg-artist-detail-header",
            );
            if (isUser && artistUserId) {
              headerContainer.innerHTML = `
                      <img src="/cover/${sampleSong.id}" onerror="this.onerror=null; this.src='?action=get_app_icon';" class="rounded-circle shadow-lg border border-secondary" style="width: 72px; height: 72px; object-fit: cover; flex-shrink: 0;">
                      <div class="d-flex flex-column justify-content-center flex-grow-1" style="min-width: 0; text-align: left;">
                        <h4 class="text-white fw-bold mb-1 text-truncate" style="font-size: 1.4rem; max-width: 100%;">${escapeHTML(artistName)}</h4>
                        <div class="d-flex flex-column align-items-start text-secondary small gap-1" style="font-weight: 500;">
                          <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span>Played: <b class="text-white">${artistScores.length}</b> times</span>
                            <span>Score: <b class="text-white">${totalScore.toLocaleString()}</b></span>
                          </div>
                          <button class="btn btn-outline-light btn-sm rounded-pill px-3 fw-bold border-secondary text-secondary mt-1" id="rg-btn-share-artist" style="font-size: 0.7rem; height: 24px; padding: 0 10px; display: inline-flex; align-items: center; gap: 4px; transition: 0.2s;"><i class="bi bi-share-fill"></i> Share</button>
                        </div>
                      </div>
                      <div class="d-flex flex-column align-items-end flex-shrink-0">
                        <span class="badge d-flex align-items-center justify-content-center" style="font-family: 'Arial Black', sans-serif; font-size: 1.1rem; font-weight: 900; background-color: ${rankColor}; color: #000; height: 42px; min-width: 48px; border-radius: 8px; box-shadow: 0 4px 12px ${rankColor}40;" title="AVERAGE RANK">${displayRank}</span>
                      </div>
                    `;
    
              document.getElementById("rg-btn-share-artist").onclick = (e) => {
                e.stopPropagation();
                showShareModal("game", "", artistName, artistUserId, artistName);
              };
            } else {
              headerContainer.innerHTML = `
                      <img src="/cover/${sampleSong.id}" onerror="this.onerror=null; this.src='?action=get_app_icon';" class="rounded-circle shadow-lg border border-secondary" style="width: 72px; height: 72px; object-fit: cover; flex-shrink: 0;">
                      <div class="d-flex flex-column justify-content-center flex-grow-1" style="min-width: 0; text-align: left;">
                        <h4 class="text-white fw-bold mb-1 text-truncate" style="font-size: 1.4rem; max-width: 100%;">${escapeHTML(artistName)}</h4>
                        <div class="d-flex flex-column align-items-start text-secondary small gap-1" style="font-weight: 500;">
                          <span class="badge bg-dark border border-secondary text-secondary">Guest Artist</span>
                          <button class="btn btn-outline-light btn-sm rounded-pill px-3 fw-bold border-secondary text-secondary mt-1" id="rg-btn-share-artist" style="font-size: 0.7rem; height: 24px; padding: 0 10px; display: inline-flex; align-items: center; gap: 4px; transition: 0.2s;"><i class="bi bi-share-fill"></i> Share</button>
                        </div>
                      </div>
                    `;
    
              document.getElementById("rg-btn-share-artist").onclick = (e) => {
                e.stopPropagation();
                showShareModal("game", "", artistName, "", artistName); // No User ID for guest artists
              };
            }
    
            // Render History list for this artist's personal plays
            const histContainer = document.getElementById("rg-list-artist-history");
            if (!isUser) {
              histContainer.innerHTML = `<div class="text-center p-5 text-secondary"><i class="bi bi-person-fill-x d-block fs-1 mb-2"></i>Only registered artist user accounts have an active play history.</div>`;
            } else if (artistScores.length === 0) {
              histContainer.innerHTML = `<div class="text-center p-5 text-secondary"><i class="bi bi-controller d-block fs-1 mb-2"></i>This user hasn't played any tracks yet.</div>`;
            } else {
              const diffColors = {
                easy: "#22d3ee",
                medium: "#34d399",
                hard: "#fb923c",
                expert: "#f43f5e",
                master: "#a78bfa",
                demon: "#7f1d1d",
              };
              const diffLabels = {
                easy: "EZ",
                medium: "ND",
                hard: "HD",
                expert: "EX",
                master: "MS",
                demon: "DM",
              };
    
              histContainer.innerHTML = artistScores
                .map((s) => {
                  const jud = this.getRankFromStats(
                    s.perfect,
                    s.great,
                    s.good,
                    s.bad,
                    s.miss,
                  );
                  const diffColor = diffColors[s.difficulty] || "#aaa";
                  const diffLabel = diffLabels[s.difficulty] || "UN";
                  const lvlText = s.level ? `Lv.${s.level}` : "Lv.--";
                  const coverSvg = getSvgPlaceholder(s.title);
                  const playedAt = s.played_at
                    ? new Date(
                        s.played_at.replace(" ", "T") + "Z",
                      ).toLocaleDateString()
                    : "Unknown";
                  const totalHits =
                    parseInt(s.perfect || 0) +
                    parseInt(s.great || 0) +
                    parseInt(s.good || 0) +
                    parseInt(s.bad || 0) +
                    parseInt(s.miss || 0);
                  const suspicious =
                    (jud.acc === "100.00" && totalHits > 50) ||
                    (s.max_combo > 500 && s.miss == 0)
                      ? '<span class="badge bg-danger ms-2" style="font-size: 0.6rem;" title="Perfect Play / Bot Suspected"><i class="bi bi-exclamation-triangle-fill"></i> SUS</span>'
                      : "";
    
                  return `
                        <div class="rg-list-item" style="background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.01) 100%); border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid ${diffColor}; border-radius: 12px; padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; cursor: default;">
                          <div class="d-flex align-items-center justify-content-between gap-3" style="min-width: 0; width: 100%;">
                            <div class="d-flex align-items-center gap-3" style="min-width: 0; flex: 1;">
                              <img src="/cover/${s.song_id}" onerror="this.onerror=null; this.src='${coverSvg}'" style="width: 44px; height: 44px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 8px rgba(0,0,0,0.4); flex-shrink: 0;">
                              <div class="text-truncate text-start" style="min-width: 0; flex: 1;">
                                <div class="text-white fw-bold text-truncate" style="font-size: 0.95rem;">${escapeHTML(s.title)}</div>
                                <div class="text-secondary small mt-1" style="font-size: 0.75rem;">by ${escapeHTML(s.artist)}</div>
                              </div>
                            </div>
                            <div class="d-flex align-items-center gap-1" style="flex-shrink: 0;">
                              ${suspicious}
                              <span class="badge d-flex align-items-center justify-content-center" style="font-family: monospace; font-size: 0.65rem; font-weight: 800; background-color: ${diffColor}; color: ${s.difficulty === "demon" ? "#fff" : "#000"}; padding: 2px 6px; border-radius: 4px;">${diffLabel} ${lvlText}</span>
                              <span class="badge d-flex align-items-center justify-content-center" style="font-family: monospace; font-size: 0.65rem; font-weight: 800; background-color: ${jud.color}; color: #000;">${jud.rank}</span>
                            </div>
                          </div>
    
                          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 100%; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 8px; margin-top: 4px; text-align: center;">
                            <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                              <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Score</span>
                              <span style="font-size: 0.9rem; font-weight: 900; font-family: monospace; color: var(--rg-primary);">${parseInt(s.score).toLocaleString()}</span>
                            </div>
                            <div style="grid-column: span 2; display: flex; justify-content: space-between; align-items: center; background-color: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding: 4px 10px; border-radius: 6px; text-align: left;">
                              <span style="font-size: 0.65rem; font-weight: 700; color: #888; text-transform: uppercase;">Combo</span>
                              <span style="font-size: 0.9rem; font-weight: 900; font-family: monospace; color: #fff;">${s.max_combo}x</span>
                            </div>
                          </div>
                        </div>
                      `;
                })
                .join("");
            }
    
            this.activeArtistDetailId = artistUserId;
    
            // Render Playlists list for this artist
            const playContainer = document.getElementById("rg-list-artist-playlists");
            if (playContainer) {
              if (
                uStmt &&
                uStmt.details &&
                uStmt.details.playlists &&
                uStmt.details.playlists.length > 0
              ) {
                playContainer.innerHTML = uStmt.details.playlists
                  .slice(0, 25)
                  .map(
                    (p) => `
                        <div class="rg-list-item rg-artist-playlist-item" data-id="${p.public_id}" data-name="${escapeHTML(p.name).replace(/"/g, "&quot;")}" style="background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.01) 100%); border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid var(--rg-primary); border-radius: 12px; padding: 12px 16px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                          <img src="/cover/${p.image_id || 0}" style="width: 50px; height: 50px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 8px rgba(0,0,0,0.4); flex-shrink: 0;">
                          <div class="text-truncate flex-grow-1" style="min-width: 0;">
                            <div class="text-white fw-bold text-truncate" style="font-size: 1rem;">${p.is_private == 1 ? '<i class="bi bi-lock-fill text-warning me-1"></i>' : ""}${escapeHTML(p.name)}</div>
                            <div class="text-secondary small mt-1" style="font-size: 0.75rem;"><i class="bi bi-music-note-list text-danger"></i> ${p.song_count} Tracks</div>
                          </div>
                          <i class="bi bi-chevron-right text-secondary fs-5" style="flex-shrink: 0;"></i>
                        </div>
                      `,
                  )
                  .join("");
    
                // Securely bind click events natively
                playContainer
                  .querySelectorAll(".rg-artist-playlist-item")
                  .forEach((item) => {
                    item.addEventListener("click", () => {
                      this.loadRgPlaylistSongs(item.dataset.id, item.dataset.name);
                    });
                  });
              } else {
                playContainer.innerHTML = `<div class="text-center p-5 text-secondary"><i class="bi bi-music-note-list d-block fs-1 mb-2"></i>No playlists found.</div>`;
              }
            }
    
            // Render Favorites list for this artist
            if (isUser && artistUserId) {
              const favsSearchInput = document.getElementById(
                "rg-search-artist-favs",
              );
              if (favsSearchInput) favsSearchInput.value = "";
              this.loadArtistFavorites(artistUserId);
              this.loadArtistFollowing(artistUserId);
            } else {
              const favsContainer = document.getElementById("rg-list-artist-favs");
              if (favsContainer) {
                favsContainer.innerHTML = `<div class="text-center p-5 text-secondary"><i class="bi bi-heart-break-fill d-block fs-1 mb-2"></i>Only registered users can have favorites.</div>`;
              }
              const folContainer = document.getElementById(
                "rg-list-artist-following",
              );
              if (folContainer) {
                folContainer.innerHTML = `<div class="text-center p-5 text-secondary"><i class="bi bi-person-fill-x d-block fs-1 mb-2"></i>Only registered users can follow others.</div>`;
              }
            }
    
            document.getElementById("rg-search-artist-songs").value = "";
    
            // Hide ALL active tabs first to prevent vertical overlapping conflicts
            Object.values(this.tabs).forEach((t) => {
              if (t) t.classList.add("rg-hidden");
            });
    
            // Show only the artist detail view
            if (this.tabs.artistDetail) {
              this.tabs.artistDetail.classList.remove("rg-hidden");
            }
    
            // Sync bottom navigation highlight conditionally (Profile vs Artists)
            const isMyOwnProfile =
              typeof currentUser !== "undefined" &&
              currentUser &&
              artistName.toLowerCase() === currentUser.artist.toLowerCase();
            document.querySelectorAll(".rg-nav-item").forEach((n) => {
              if (isMyOwnProfile) {
                n.classList.toggle("active", n.id === "rg-nav-my-profile");
              } else {
                n.classList.toggle(
                  "active",
                  n.getAttribute("data-target") === "artists",
                );
              }
            });
    
            // Default to correct sub-tab based on device size
            if (window.innerWidth >= 992) {
              const artTabHistory = document.getElementById("rg-art-tab-history");
              if (artTabHistory) artTabHistory.click();
            } else {
              const artTabTracks = document.getElementById("rg-art-tab-tracks");
              if (artTabTracks) artTabTracks.click();
            }
    
            this.loadArtistSongs(artistName);
          }
    
          openArtistChoiceDialog(artistsList) {
            const body = document.getElementById("artists-modal-body");
            if (!body) return;
    
            const modalEl = document.getElementById("artists-modal");
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    
            const modalTitle = modalEl.querySelector(".modal-title");
            if (modalTitle) modalTitle.textContent = "Artists";
    
            body.innerHTML = `
                    <div class="list-group list-group-flush rounded">
                      ${artistsList
                        .map(
                          (a) => `
                        <button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary rhythm-artist-modal-item py-3 text-start px-4" style="border-left: none; border-right: none;" data-artist="${encodeURIComponent(a)}">
                          ${a}
                        </button>
                      `,
                        )
                        .join("")}
                    </div>
                  `;
    
            // Re-bind clicks directly to open the selected artist profile
            body.querySelectorAll(".rhythm-artist-modal-item").forEach((btn) => {
              btn.addEventListener("click", () => {
                modal.hide();
                this.openArtistDetail(decodeURIComponent(btn.dataset.artist));
              });
            });
    
            modal.show();
          }
    
          async endGame(failed = false) {
            this.isPlaying = false;
            if (this.audioPlayback) this.audioPlayback.pause();
    
            if (this.autoplayWakeLocked) {
              if (typeof noSleep !== "undefined") noSleep.disable();
              this.autoplayWakeLocked = false;
            }
    
            this.switchScreen("result");
    
            // Inject dynamic blurred cover background to results screen
            const resultScreen = document.getElementById("rg-screen-result");
            if (resultScreen) {
              resultScreen.style.backgroundColor = "transparent";
              let resultBg = document.getElementById("rg-result-bg");
              if (!resultBg) {
                resultBg = document.createElement("div");
                resultBg.id = "rg-result-bg";
                resultBg.style.cssText =
                  "position:absolute; top:-40px; left:-40px; right:-40px; bottom:-40px; background-size:cover; background-position:center; filter:blur(40px) brightness(0.35); z-index:0; pointer-events:none; transition: background-image 0.5s ease;";
                resultScreen.insertBefore(resultBg, resultScreen.firstChild);
              }
              if (this.selectedSong) {
                resultBg.style.backgroundImage = `url('/cover/${this.selectedSong.id}')`;
              }
            }
    
            const resultWarningId = "rg-offline-result-warning";
            let resultWarningEl = document.getElementById(resultWarningId);
            if (resultWarningEl) resultWarningEl.remove();
    
            if (!navigator.onLine && !failed && !this.isAutoplay) {
              const resultButtonContainer = document.getElementById(
                "rg-btn-result-share",
              ).parentNode;
              resultButtonContainer.insertAdjacentHTML(
                "beforebegin",
                `
                      <div id="${resultWarningId}" class="alert border-danger text-danger small mb-3 text-center rounded-3 w-100" style="background: rgba(255,59,48,0.1); line-height: 1.4; font-weight: bold; border-style: dashed;">
                        <i class="bi bi-shield-slash-fill me-2"></i>Score Not Saved: Played while offline. This score will NOT be synced to the database.
                      </div>
                    `,
              );
            }
    
            const titleEl = document.querySelector("#rg-screen-result h2");
            if (titleEl) {
              titleEl.textContent = failed ? "STAGE FAILED" : "STAGE CLEARED";
              titleEl.style.color = failed ? "#ff5f56" : "var(--rg-primary)";
            }
            const shareBtn = document.getElementById("rg-btn-result-share");
            if (shareBtn) {
              shareBtn.style.display = failed ? "none" : "block";
            }
    
            let rank = "F";
            let rankColor = "#7f1d1d";
            const accVal = parseFloat(this.accuracy);
    
            if (accVal >= 98.0) {
              rank = "SS";
              rankColor = "#ff0055";
            } else if (accVal >= 95.0) {
              rank = "S";
              rankColor = "#ffbd2e";
            } else if (accVal >= 90.0) {
              rank = "A";
              rankColor = "#a78bfa";
            } else if (accVal >= 80.0) {
              rank = "B";
              rankColor = "#60a5fa";
            } else if (accVal >= 70.0) {
              rank = "C";
              rankColor = "#34d399";
            } else if (accVal >= 60.0) {
              rank = "D";
              rankColor = "#fb923c";
            } else if (accVal >= 50.0) {
              rank = "E";
              rankColor = "#9ca3af";
            }
    
            if (failed) {
              rank = "F";
              rankColor = "#ff5f56";
            }
    
            const rankEl = document.getElementById("rg-res-rank");
            if (rankEl) {
              rankEl.textContent = rank;
              rankEl.style.color = rankColor;
            }
    
            const accEl = document.getElementById("rg-res-acc");
            if (accEl) {
              accEl.textContent = this.accuracy + "%";
            }
    
            let maxScore = 0;
            this.chartNotes.forEach((n) => {
              if (n.isLong) {
                maxScore += n.hitEndSwipe ? 350 : 110;
              } else {
                maxScore += n.isSwipe ? 150 : 100;
              }
            });
            if (maxScore === 0) maxScore = 1;
            const pct = Math.min(100, Math.max(0, (this.curScore / maxScore) * 100));
            const scoreBar = document.getElementById("rg-res-score-bar");
            if (scoreBar) {
              scoreBar.style.width = "0%";
              setTimeout(() => {
                scoreBar.style.width = pct + "%";
              }, 100);
            }
    
            document.getElementById("rg-res-score").textContent = String(
              this.curScore,
            ).padStart(9, "0");
            document.getElementById("rg-res-max-combo").textContent = this.maxCombo;
            document.getElementById("rg-res-perfect").textContent =
              this.stats.perfect;
            document.getElementById("rg-res-great").textContent = this.stats.great;
            document.getElementById("rg-res-good").textContent = this.stats.good;
            document.getElementById("rg-res-bad").textContent = this.stats.bad;
            document.getElementById("rg-res-miss").textContent = this.stats.miss;
    
            const resTitle = document.querySelector("#rg-screen-result h2");
            if (this.isAutoplay && resTitle) {
              resTitle.textContent = "AUTOPLAY (UNRANKED)";
              resTitle.style.color = "#00bcd4";
            }
    
            if (
              !failed &&
              this.curScore > 0 &&
              this.selectedSong &&
              !this.isAutoplay
            ) {
              await fetchData("?action=save_rhythm_score", {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                },
                body: JSON.stringify({
                  song_id: this.selectedSong.id,
                  score: this.curScore,
                  max_combo: this.maxCombo,
                  perfect: this.stats.perfect,
                  great: this.stats.great,
                  good: this.stats.good,
                  bad: this.stats.bad,
                  miss: this.stats.miss,
                  difficulty: this.selectedDiff,
                  hit_deltas: this.hitDeltas,
                }),
              });
              this.loadLeaderboard();
            }
          }
        }

    // ---- Boot ----
    // Tear down any previous instance first (partial-nav re-runs this script).
    if (window.rhythmEngine && typeof window.rhythmEngine.destroy === 'function') {
        try { window.rhythmEngine.destroy(); } catch (e) {}
    }
    try {
        window.rhythmEngine = new RhythmGameEngine();
        // The legacy view-loader constructed the engine then called initDOM()
        // separately; initDOM() wires the DOM, hides all but the hub screen
        // (switchScreen('hub')) and kicks off the first song load.
        window.rhythmEngine.initDOM();
    } catch (e) {
        console.error('[rhythm] engine init failed', e);
    }

    // Tear the engine down when navigating AWAY from /rhythm. player.js owns
    // window.appPageInit and defines it via a deferred script, so on a full
    // page load we wait for the load event; on a partial-nav re-run it is
    // already present. Hook it exactly once.
    function installNavHook() {
        if (window.__rhythmNavHooked) return;
        window.__rhythmNavHooked = true;
        const prev = window.appPageInit;
        window.appPageInit = function () {
            if (typeof prev === 'function') prev();
            if (!document.getElementById('rg-screen-hub') && window.rhythmEngine) {
                try { window.rhythmEngine.destroy(); } catch (e) {}
                window.rhythmEngine = null;
            }
        };
    }
    if (typeof window.appPageInit === 'function') {
        installNavHook();
    } else {
        window.addEventListener('load', installNavHook);
    }
})();
