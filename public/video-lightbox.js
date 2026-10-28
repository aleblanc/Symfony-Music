/*
 * YouTube lightbox: any [data-video-play="<id>"] button opens the video in a
 * <dialog> (youtube-nocookie.com embed: no YouTube cookie until it plays).
 * Delegated on document, so buttons swapped in by nav.js work too. The
 * library player is paused while a video plays; closing (✕, Esc, a click
 * outside the frame) stops the video.
 */
(function () {
    const box = document.getElementById('video-lightbox');
    if (!box) return;
    const frame = box.querySelector('iframe');
    const title = box.querySelector('.video-lightbox-title');

    function close() {
        if (box.open) box.close();
    }

    document.addEventListener('click', (e) => {
        const button = e.target instanceof Element ? e.target.closest('[data-video-play]') : null;
        if (!button) return;
        e.preventDefault();
        const id = button.getAttribute('data-video-play');
        if (!/^[A-Za-z0-9_-]{6,20}$/.test(id || '')) return;
        document.querySelectorAll('audio').forEach((a) => a.pause());
        title.textContent = button.getAttribute('data-video-title') || '';
        frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
        box.showModal();
    });
    box.addEventListener('close', () => { frame.src = 'about:blank'; });
    box.querySelector('.video-lightbox-close').addEventListener('click', close);
    // A click on the backdrop (the dialog itself, not its content) closes it.
    box.addEventListener('click', (e) => { if (e.target === box) close(); });
})();
