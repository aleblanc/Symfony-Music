/*
 * Lightbox: a [data-video-play="<id>"] button opens the YouTube video in a
 * <dialog> (youtube-nocookie.com embed: no YouTube cookie until it plays), a
 * [data-instagram-play="<shortcode>"] one Instagram's own embed of the post.
 * Delegated on document, so buttons swapped in by nav.js work too. An
 * Instagram post leaves the music playing; a YouTube video pauses it, and
 * closing (✕, Esc, a click outside the frame) stops the video and resumes
 * the music that was playing.
 */
(function () {
    const box = document.getElementById('video-lightbox');
    if (!box) return;
    const frame = box.querySelector('iframe');
    const title = box.querySelector('.video-lightbox-title');
    /** The players the video paused, played again on close. */
    let paused = [];

    function close() {
        if (box.open) box.close();
    }

    document.addEventListener('click', (e) => {
        const button = e.target instanceof Element ? e.target.closest('[data-video-play], [data-instagram-play]') : null;
        if (!button) return;
        e.preventDefault();
        const instagram = button.hasAttribute('data-instagram-play');
        const id = button.getAttribute(instagram ? 'data-instagram-play' : 'data-video-play');
        if (!/^[A-Za-z0-9_-]{6,40}$/.test(id || '')) return;
        if (!instagram) {
            paused = Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused);
            paused.forEach((a) => a.pause());
        }
        title.textContent = button.getAttribute('data-video-title') || '';
        box.classList.toggle('is-instagram', instagram);
        frame.src = instagram
            ? 'https://www.instagram.com/p/' + id + '/embed/captioned/'
            : 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
        box.showModal();
    });
    box.addEventListener('close', () => {
        frame.src = 'about:blank';
        paused.forEach((a) => a.play().catch(() => {}));
        paused = [];
    });
    box.querySelector('.video-lightbox-close').addEventListener('click', close);
    // A click on the backdrop (the dialog itself, not its content) closes it.
    box.addEventListener('click', (e) => { if (e.target === box) close(); });
})();
