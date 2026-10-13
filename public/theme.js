(() => {
    'use strict';

    const storageKey = 'internbridge-theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const validTheme = value => value === 'light' || value === 'dark';
    let preference = null;

    try {
        const saved = window.localStorage.getItem(storageKey);
        if (validTheme(saved)) preference = saved;
    } catch { /* A blocked preference store must never block the page. */ }

    const updateControls = () => {
        const dark = root.dataset.theme === 'dark';
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            const label = dark ? button.dataset.labelLight : button.dataset.labelDark;
            button.setAttribute('aria-pressed', String(dark));
            if (label) {
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            }
            button.querySelectorAll('[data-theme-icon]').forEach(icon => {
                icon.toggleAttribute('hidden', icon.dataset.themeIcon !== (dark ? 'sun' : 'moon'));
            });
            button.hidden = false;
        });
        const color = dark ? '#142219' : '#edf0e3';
        document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
            meta.setAttribute('content', color);
        });
    };

    const applyTheme = () => {
        root.dataset.theme = preference || (system.matches ? 'dark' : 'light');
        updateControls();
    };

    // Run before styles paint; no request, framework render or navigation is needed.
    applyTheme();

    const initialize = () => {
        updateControls();
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
                try { window.localStorage.setItem(storageKey, preference); } catch { /* Keep this page usable. */ }
                applyTheme();
            });
        });
        const updateVisibility = () => document.body.classList.toggle('is-page-hidden', document.hidden);
        updateVisibility();
        document.addEventListener('visibilitychange', updateVisibility);
        window.addEventListener('pagehide', () => document.body.classList.add('is-page-hidden'));
        window.addEventListener('pageshow', event => {
            updateVisibility();
            // A page restored from the back-forward cache may have missed storage events.
            if (event.persisted) {
                try {
                    const saved = window.localStorage.getItem(storageKey);
                    preference = validTheme(saved) ? saved : null;
                } catch { /* Retain the current in-memory choice. */ }
            }
            applyTheme();
        });
    };

    system.addEventListener('change', () => {
        if (!preference) applyTheme();
    });
    window.addEventListener('storage', event => {
        if (event.key !== storageKey && event.key !== null) return;
        preference = validTheme(event.newValue) ? event.newValue : null;
        applyTheme();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
