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

    async function navigate(href, { push = true, method = 'GET', body = null } = {}) {
        let res;
        try {
            res = await fetch(href, { method, body, headers: { 'X-Requested-With': 'fetch' }, redirect: 'follow' });
        } catch {
            location.href = href; // network error: fall back to a hard load
            return;
        }

        const finalUrl = res.url || href;
        const ct = res.headers.get('Content-Type') || '';
        if (!ct.includes('text/html')) { location.href = finalUrl; return; } // downloads, JSON, etc.

        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
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

        if (typeof window.appPageInit === 'function') window.appPageInit();
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
