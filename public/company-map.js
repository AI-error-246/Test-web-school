(() => {
    'use strict';

    document.querySelectorAll('[data-company-map]').forEach(card => {
        const button = card.querySelector('[data-map-load]');
        const canvas = card.querySelector('[data-map-canvas]');
        const placeholder = card.querySelector('[data-map-placeholder]');
        const status = card.querySelector('[data-map-status]');
        if (!button || !canvas || !placeholder || !status) return;

        button.hidden = false;
        button.addEventListener('click', () => {
            if (card.dataset.mapRequested === 'true') return;
            let source;
            try {
                source = new URL(card.dataset.mapSrc);
                if (source.origin !== 'https://www.google.com' || source.pathname !== '/maps'
                    || source.searchParams.get('output') !== 'embed' || !source.searchParams.get('q')) {
                    throw new Error('Invalid map source');
                }
            } catch {
                status.textContent = card.dataset.mapError;
                return;
            }

            card.dataset.mapRequested = 'true';
            button.disabled = true;
            status.textContent = card.dataset.mapLoading;
            const frame = document.createElement('iframe');
            frame.title = card.dataset.mapTitle;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.allowFullscreen = true;
            frame.addEventListener('load', () => {
                status.textContent = card.dataset.mapLoaded;
            }, { once: true });
            frame.addEventListener('error', () => {
                frame.remove();
                placeholder.hidden = false;
                button.disabled = false;
                card.dataset.mapRequested = 'false';
                status.textContent = card.dataset.mapError;
            }, { once: true });
            frame.src = source.href;
            placeholder.hidden = true;
            canvas.append(frame);
            frame.focus({ preventScroll: true });
        });
    });
})();
