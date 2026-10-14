(() => {
    'use strict';
    const notice = document.querySelector('#preview-notice');
    let timer;
    const explain = () => {
        if (!notice) return;
        notice.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(() => { notice.hidden = true; }, 6500);
    };

    // Preview actions never serialize inputs, submit forms, or contact an API.
    document.addEventListener('submit', event => {
        event.preventDefault();
        event.stopImmediatePropagation();
        explain();
    }, true);
    document.addEventListener('click', event => {
        if (event.target.closest('[data-preview-action], [data-map-load], [data-notification-retry], [data-support-question], [data-support-clear]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            explain();
        }
    }, true);
    document.querySelectorAll('select[data-vi]').forEach(select => {
        select.closest('form')?.classList.add('is-enhanced');
        select.addEventListener('change', () => {
            const target = select.dataset[select.value];
            if (target && /^(vi|en|ja)-[a-zA-Z0-9-]+\.html$/.test(target)) location.assign(target);
        });
    });
    const widget = document.querySelector('[data-support-widget]');
    const panel = widget?.querySelector('#support-panel');
    const launcher = widget?.querySelector('.support-launcher');
    if (widget && panel && launcher) {
        widget.hidden = false;
        launcher.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            launcher.setAttribute('aria-expanded', String(!panel.hidden));
        });
        widget.querySelector('[data-support-close]')?.addEventListener('click', () => {
            panel.hidden = true;
            launcher.setAttribute('aria-expanded', 'false');
            launcher.focus();
        });
    }
})();
