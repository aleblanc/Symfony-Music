/*
 * Partial navigation ("mini-Turbo", vanilla, ~100 lines).
 *
 * Intercepts internal <a> clicks and <form> submits, fetches the target,
 * and swaps ONLY <main class="content"> + <title> + the sidebar nav (for the
 * active state). The persistent <footer id="player"> and <audio> live OUTSIDE
 * <main>, so they are never touched — playback and the queue survive every
 * navigation. URLs stay real via history.pushState / popstate.
 *
 * Page-specific initialisation (e.g. the song page audio-settings panel) is
 * re-run after each swap through the global window.appPageInit() hook that
 * player.js registers.
 */
(function () {
    const main = document.querySelector('main.content');
    if (!main) return;
    // Standalone app pages (e.g. /rhythm) manage their own history/DOM: an
    // inline script sets this flag BEFORE this deferred script runs. Partial
    // navigation there would swap <main> and orphan the running JS engine.
    if (window.NO_PARTIAL_NAV) return;

    const sameOrigin = (url) => url.origin === location.origin;

    // Inserting <script> nodes via DOM does not execute them; recreate each one
    // so inline page scripts (modals, small form helpers) run after a swap.
    function runScripts(container) {
        container.querySelectorAll('script').forEach((old) => {
            const s = document.createElement('script');
            for (const a of old.attributes) s.setAttribute(a.name, a.value);
            s.textContent = old.textContent;
            old.replaceWith(s);
        });
    }

    // Only the latest click counts: a new navigation aborts the one in
    // flight, so a slow page can never swap in over a newer one. A page that
    // does not answer within SLOW_MS is loaded the normal way instead, so a
    // stuck request never leaves the links looking dead.
    const SLOW_MS = 10000;
    let current = null;

    async function navigate(href, { push = true, method = 'GET', body = null } = {}) {
        if (current) current.abort();
        const controller = new AbortController();
        current = controller;
        let slow = false;
        const timer = setTimeout(() => { slow = true; controller.abort(); }, SLOW_MS);
        document.documentElement.classList.add('nav-loading');
        const done = () => {
            clearTimeout(timer);
            if (current === controller) {
                current = null;
                document.documentElement.classList.remove('nav-loading');
            }
        };

        let res;
        let html;
        try {
            res = await fetch(href, { method, body, headers: { 'X-Requested-With': 'fetch' }, redirect: 'follow', signal: controller.signal });
            html = (res.headers.get('Content-Type') || '').includes('text/html') ? await res.text() : null;
        } catch {
            done();
            // Superseded by a newer click: nothing to do. Too slow, or a
            // network error: fall back to a hard load (a POST is not replayed).
            if (!slow && controller.signal.aborted) return;
            if (method === 'GET') location.href = href;
            return;
        }
        done();
        if (controller.signal.aborted) return;

        const finalUrl = res.url || href;
        if (html === null) { location.href = finalUrl; return; } // downloads, JSON, etc.

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newMain = doc.querySelector('main.content');
        if (!newMain) { location.href = finalUrl; return; } // unexpected shape: hard load

        main.replaceChildren(...newMain.childNodes);
        runScripts(main);

        if (doc.title) document.title = doc.title;

        // Refresh the sidebar nav so the active link matches the new page.
        const oldNav = document.querySelector('.sidebar nav');
        const newNav = doc.querySelector('.sidebar nav');
        if (oldNav && newNav) oldNav.replaceChildren(...newNav.childNodes);

        if (push) history.pushState({ nav: true }, '', finalUrl);
        window.scrollTo(0, 0);

        // A page script error must not break the next navigations.
        try {
            if (typeof window.appPageInit === 'function') window.appPageInit();
        } catch (err) {
            console.error(err);
        }
    }

    // ---- Link interception ----
    document.addEventListener('click', (e) => {
        // player.js / the context menu preventDefault their own clicks first.
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || a.target === '_blank'
            || a.hasAttribute('download') || a.dataset.noNav !== undefined) return;

        let url;
        try { url = new URL(a.href, location.href); } catch { return; }
        if (!sameOrigin(url)) return;
        // Never intercept media/asset endpoints — let the browser handle them.
        if (/^\/(stream|cover|assets|manifest\.json|sw\.js|icon\.svg)/.test(url.pathname)) return;

        e.preventDefault();
        navigate(url.href);
    });

    // ---- Form interception ----
    document.addEventListener('submit', (e) => {
        // Forms with their own JS handler already called preventDefault (e.g.
        // profile picture upload) — skip those.
        if (e.defaultPrevented) return;
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.noNav !== undefined) return;

        let action;
        try { action = new URL(form.getAttribute('action') || location.href, location.href); } catch { return; }
        if (!sameOrigin(action)) return;

        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        e.preventDefault();

        if (method === 'GET') {
            action.search = new URLSearchParams(new FormData(form)).toString();
            navigate(action.href);
        } else {
            navigate(action.href, { method, body: new FormData(form) });
        }
    });

    window.addEventListener('popstate', () => navigate(location.href, { push: false }));
})();
