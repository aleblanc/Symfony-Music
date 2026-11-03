/*
 * Sortable track lists (partials/_tracks.html.twig, sortable = true): a click
 * on a column title sorts the rows in the page, a second click reverses it.
 * Ratings and durations start with the highest, the others A → Z; equal
 * values keep title order, then the original one. The rows are moved, not
 * rebuilt, so the player's queue (built from the page) follows the order
 * shown. Delegated on document, so lists swapped in by nav.js work too.
 */
(function () {
    const collator = new Intl.Collator(document.documentElement.lang || undefined, { sensitivity: 'base', numeric: true });
    const DESC_FIRST = { rating: true, dur: true };

    const value = {
        idx: (row) => Number(row.dataset.sortIdx || 0),
        title: (row) => row.dataset.trackTitle || '',
        album: (row) => row.dataset.trackAlbum || '',
        dur: (row) => Number(row.dataset.sortDur || 0),
        rating: (row) => {
            const box = row.querySelector('.rate[data-stars]');
            return box ? Number(box.dataset.stars || 0) : 0;
        },
    };
    const compare = (a, b) => (typeof a === 'number' ? a - b : collator.compare(a, b));

    document.addEventListener('click', (e) => {
        const button = e.target instanceof Element ? e.target.closest('.track-list--sortable [data-sort-key]') : null;
        if (!button) return;
        const list = button.closest('.track-list--sortable');
        const key = button.dataset.sortKey;
        const get = value[key];
        if (!get) return;
        const current = button.getAttribute('aria-sort');
        const desc = current ? current === 'ascending' : !!DESC_FIRST[key];

        const rows = Array.from(list.querySelectorAll(':scope > [data-track]'));
        rows.sort((a, b) => compare(get(a), get(b)) * (desc ? -1 : 1)
            || (key !== 'title' && key !== 'idx' ? collator.compare(value.title(a), value.title(b)) : 0)
            || value.idx(a) - value.idx(b));
        rows.forEach((row) => list.appendChild(row));

        list.querySelectorAll('[data-sort-key]').forEach((b) => b.removeAttribute('aria-sort'));
        button.setAttribute('aria-sort', desc ? 'descending' : 'ascending');
    });
})();
