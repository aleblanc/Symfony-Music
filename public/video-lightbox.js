/*
 * Lightbox: a [data-video-play="<id>"] button opens the YouTube video in a
 * <dialog> (youtube-nocookie.com embed: no YouTube cookie until it plays), a
 * [data-instagram-play="<shortcode>"] one Instagram's own embed of the post.
 * Delegated on document, so buttons swapped in by nav.js work too. ‹ › (and
 * the ← → keys) go to the previous / next card of the same list without
 * closing. A YouTube video pauses the music, an Instagram post leaves it
 * playing; closing (✕, Esc, a click outside the frame) stops the video and
 * resumes the music that was playing.
 */
(function () {
    const box = document.getElementById('video-lightbox');
    if (!box) return;
    const frame = box.querySelector('iframe');
    const title = box.querySelector('.video-lightbox-title');
    const prev = box.querySelector('.video-lightbox-prev');
    const next = box.querySelector('.video-lightbox-next');
    const SELECTOR = '[data-video-play], [data-instagram-play]';
    /** The players the video paused, played again on close. */
    let paused = [];
    /** The play buttons of the list the open card belongs to, and its position in it. */
    let list = [];
    let index = -1;

    function close() {
        if (box.open) box.close();
    }

    /** The play buttons of the card's grid (its parent), or of the whole page outside a grid. */
    function siblings(button) {
        const card = button.closest('.video-card');
        const scope = card && card.parentElement && card.parentElement.querySelectorAll(SELECTOR).length > 1 ? card.parentElement : document;
        return Array.from(scope.querySelectorAll(SELECTOR));
    }

    function open(button) {
        const instagram = button.hasAttribute('data-instagram-play');
        const id = button.getAttribute(instagram ? 'data-instagram-play' : 'data-video-play');
        if (!/^[A-Za-z0-9_-]{6,40}$/.test(id || '')) return false;
        if (!instagram) {
            // Keep the players paused by an earlier video of the list, add the ones playing now.
            const playing = Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused);
            playing.forEach((a) => a.pause());
            paused = paused.concat(playing.filter((a) => !paused.includes(a)));
        }
        title.textContent = button.getAttribute('data-video-title') || '';
        box.classList.toggle('is-instagram', instagram);
        frame.src = instagram
            ? 'https://www.instagram.com/p/' + id + '/embed/captioned/'
            : 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
        index = list.indexOf(button);
        prev.hidden = index <= 0;
        next.hidden = index < 0 || index >= list.length - 1;
        return true;
    }

    function go(step) {
        const button = list[index + step];
        if (button) open(button);
    }

    document.addEventListener('click', (e) => {
        const button = e.target instanceof Element ? e.target.closest(SELECTOR) : null;
        if (!button) return;
        e.preventDefault();
        list = siblings(button);
        if (open(button) && !box.open) box.showModal();
    });
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));
    box.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    });
    box.addEventListener('close', () => {
        frame.src = 'about:blank';
        paused.forEach((a) => a.play().catch(() => {}));
        paused = [];
        list = [];
        index = -1;
    });
    box.querySelector('.video-lightbox-close').addEventListener('click', close);
    // A click on the backdrop (the dialog itself, not its content) closes it.
    box.addEventListener('click', (e) => { if (e.target === box) close(); });
})();
