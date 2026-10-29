/*
 * Concerts map (/concerts?view=map, and the List / Map buttons of the artist
 * page): one marker per concert at its city, grouped while zoomed out. Leaflet + markercluster (public/vendor/leaflet)
 * and the OpenStreetMap tiles need no API key; they are loaded on the first
 * map only. The page renders the markers as #concert-map-data
 * ([{lat, lng, kind, popup}]); this script only places them. Re-run by
 * nav.js after each partial navigation to the page. A map in a hidden pane
 * ([data-concert-views]) is built when first shown: Leaflet needs its size.
 */
(function () {
    const box = document.getElementById('concert-map');
    if (!box || box.dataset.ready) return;
    box.dataset.ready = '1';

    const COLORS = { concert: '#f1f1f1', festival: '#ffb020', new: '#ff0033', off: '#717171' };

    function css(href) {
        if (document.querySelector(`link[href="${href}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    // Each library once per page life, even when the map is opened again.
    const loading = (window.__concertMapScripts ||= {});
    function script(src) {
        return (loading[src] ||= new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = () => { delete loading[src]; reject(new Error(src)); };
            document.head.appendChild(s);
        }));
    }

    function mount() {
        let points = [];
        try {
            points = JSON.parse(document.getElementById('concert-map-data')?.textContent || '[]');
        } catch {
            // Broken data: an empty map.
        }

        // The wheel scrolls the page, not the map: zoom with the +/- buttons, a double click or a pinch.
        const map = L.map(box, { worldCopyJump: true, minZoom: 1, scrollWheelZoom: false }).setView([30, 10], 2);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
        }).addTo(map);

        const clusters = L.markerClusterGroup({
            showCoverageOnHover: false,
            maxClusterRadius: 45,
            iconCreateFunction: (cluster) => {
                const n = cluster.getChildCount();
                const size = n < 10 ? 32 : n < 100 ? 38 : 46;
                return L.divIcon({ html: `<span>${n}</span>`, className: 'concert-cluster', iconSize: [size, size] });
            },
        });
        for (const p of points) {
            L.circleMarker([p.lat, p.lng], {
                radius: 8,
                weight: 2,
                color: '#0f0f0f',
                fillColor: COLORS[p.kind] || COLORS.concert,
                fillOpacity: 0.95,
            }).bindPopup(p.popup, { maxWidth: 280 }).addTo(clusters);
        }
        map.addLayer(clusters);
        if (points.length) {
            map.fitBounds(clusters.getBounds(), { padding: [30, 30], maxZoom: 9 });
        }
    }

    let started = false;
    function start() {
        if (started) return;
        started = true;
        css('/vendor/leaflet/leaflet.css');
        css('/vendor/leaflet/MarkerCluster.css');
        script('/vendor/leaflet/leaflet.js')
            .then(() => script('/vendor/leaflet/leaflet.markercluster.js'))
            .then(() => { if (box.isConnected) mount(); })
            .catch(() => { box.textContent = '⚠'; });
    }

    const views = box.closest('[data-concert-views]');
    if (!views) {
        start();
        return;
    }
    const tabs = views.querySelectorAll('[data-concert-tab]');
    for (const tab of tabs) {
        tab.addEventListener('click', () => {
            for (const t of tabs) {
                t.classList.toggle('active', t === tab);
                t.setAttribute('aria-pressed', String(t === tab));
            }
            for (const pane of views.querySelectorAll('[data-concert-pane]')) {
                pane.hidden = pane.dataset.concertPane !== tab.dataset.concertTab;
            }
            if (tab.dataset.concertTab === 'map') start();
        });
    }
})();
