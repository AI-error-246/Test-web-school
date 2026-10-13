(() => {
    'use strict';

    const disclosures = [...document.querySelectorAll('.toolbar-dropdown')];
    const close = (details, restoreFocus = false) => {
        details.open = false;
        if (restoreFocus) details.querySelector('summary')?.focus();
    };
    disclosures.forEach(details => {
        const summary = details.querySelector('summary');
        summary?.setAttribute('aria-expanded', String(details.open));
        details.querySelectorAll('[data-dropdown-close]').forEach(button => {
            button.hidden = false;
            button.addEventListener('click', () => close(details, true));
        });
        details.addEventListener('toggle', () => {
            summary?.setAttribute('aria-expanded', String(details.open));
            if (details.open) disclosures.filter(other => other !== details).forEach(other => close(other));
        });
    });
    document.addEventListener('click', event => {
        disclosures.filter(details => details.open && !details.contains(event.target))
            .forEach(details => close(details));
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') disclosures.filter(details => details.open)
            .forEach(details => close(details, true));
    });
    document.addEventListener('focusin', event => {
        disclosures.filter(details => details.open && !details.contains(event.target))
            .forEach(details => close(details));
    });

    const widget = document.querySelector('[data-notifications]');
    if (!widget) return;
    const trigger = widget.querySelector('summary');
    const counter = widget.querySelector('[data-notification-count]');
    const list = widget.querySelector('[data-notification-list]');
    const feedback = widget.querySelector('[data-notification-feedback]');
    const retry = widget.querySelector('[data-notification-retry]');
    const announcement = document.querySelector('[data-notification-announcement]');
    const defaultLabel = trigger.getAttribute('aria-label');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const formatter = new Intl.DateTimeFormat(document.documentElement.lang, {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
    let timer;
    let activeRequest;
    let marking = false;
    let lastLoaded = 0;
    let lastCount = null;
    let stopped = false;
    let pageActive = true;

    const safeUrl = (value, pattern) => {
        try {
            const url = new URL(value, location.origin);
            return url.origin === location.origin && pattern.test(url.pathname) ? url.href : null;
        } catch { return null; }
    };
    const displayFeedback = (message = '') => {
        feedback.textContent = message;
        feedback.hidden = !message;
    };
    const updateCount = count => {
        const value = Number.isSafeInteger(count) && count >= 0 ? count : 0;
        counter.textContent = value > 99 ? '99+' : String(value);
        counter.hidden = value === 0;
        const label = value ? widget.dataset.countLabel.replace(':count', String(value)) : defaultLabel;
        trigger.setAttribute('aria-label', label);
        if (lastCount !== null && value > lastCount) {
            announcement.textContent = label;
            trigger.classList.add('has-new');
        }
        lastCount = value;
    };
    trigger.addEventListener('animationend', () => trigger.classList.remove('has-new'));
    const sessionEnded = response => {
        if (!response.redirected && ![401, 403, 419].includes(response.status)) return false;
        stopped = true;
        window.clearTimeout(timer);
        list.replaceChildren();
        updateCount(0);
        announcement.textContent = '';
        retry.hidden = true;
        return true;
    };
    const schedule = (delay = 60000) => {
        window.clearTimeout(timer);
        if (!stopped && pageActive && !document.hidden) timer = window.setTimeout(() => load(), delay);
    };
    const markRead = async (item, button) => {
        if (marking || !csrf) return;
        const endpoint = safeUrl(item.read_url, /^\/candidate\/notifications\/\d+\/read$/);
        if (!endpoint) return;
        activeRequest?.abort();
        activeRequest = null;
        marking = true;
        button.disabled = true;
        window.clearTimeout(timer);
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            if (sessionEnded(response) || !response.ok) throw new Error('notification-read');
            const data = await response.json();
            updateCount(data.unread_count);
            button.closest('.notification-item')?.classList.remove('is-unread');
            const nextFocus = button.closest('.notification-item')?.querySelector('a');
            if (document.activeElement === button) nextFocus?.focus();
            button.remove();
            displayFeedback();
        } catch {
            button.disabled = false;
            displayFeedback(widget.dataset.error);
        } finally {
            window.clearTimeout(timeout);
            marking = false;
            lastLoaded = 0;
            schedule();
        }
    };
    const render = items => {
        const fragment = document.createDocumentFragment();
        items.slice(0, 8).forEach(item => {
            const url = safeUrl(item.url, /^\/applications\/\d+$/);
            if (!url) return;
            const row = document.createElement('li');
            row.className = 'notification-item';
            row.classList.toggle('is-unread', Boolean(item.unread));
            row.classList.toggle('is-selected', item.status === 'SELECTED');
            const link = document.createElement('a');
            link.href = url;
            const title = document.createElement('strong');
            title.className = 'notification-item-title';
            title.textContent = item.title;
            const body = document.createElement('span');
            body.className = 'notification-item-body';
            body.textContent = item.body;
            link.append(title, body);
            const meta = document.createElement('div');
            meta.className = 'notification-meta';
            const time = document.createElement('time');
            const date = new Date(item.changed_at);
            if (!Number.isNaN(date.getTime())) {
                time.dateTime = date.toISOString();
                time.textContent = formatter.format(date);
            }
            meta.append(time);
            if (item.unread) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'notification-read';
                button.textContent = widget.dataset.markRead;
                button.addEventListener('click', () => markRead(item, button));
                meta.append(button);
            }
            row.append(link, meta);
            fragment.append(row);
        });
        list.replaceChildren(fragment);
    };
    const load = async () => {
        if (activeRequest || marking || stopped || !pageActive || document.hidden) return;
        window.clearTimeout(timer);
        if (Date.now() - lastLoaded < 5000) { schedule(); return; }
        const endpoint = safeUrl(widget.dataset.feedUrl, /^\/candidate\/notifications\/feed$/);
        if (!endpoint) return;
        const controller = new AbortController();
        activeRequest = controller;
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        retry.hidden = true;
        if (!lastLoaded) displayFeedback(widget.dataset.loading);
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Accept': 'application/json' },
            });
            if (sessionEnded(response) || !response.ok) throw new Error('notification-feed');
            const data = await response.json();
            if (activeRequest !== controller) return;
            if (!Array.isArray(data.items)) throw new Error('notification-data');
            updateCount(data.unread_count);
            // Keep keyboard focus in the existing list during background refreshes.
            if (!list.contains(document.activeElement)) render(data.items);
            displayFeedback(data.items.length ? '' : widget.dataset.empty);
            lastLoaded = Date.now();
        } catch {
            if (activeRequest === controller && pageActive && !document.hidden) {
                displayFeedback(widget.dataset.error);
                retry.hidden = stopped;
            }
        } finally {
            window.clearTimeout(timeout);
            if (activeRequest === controller) { activeRequest = null; schedule(); }
        }
    };
    retry.addEventListener('click', () => { lastLoaded = 0; load(); });
    widget.addEventListener('toggle', () => { if (widget.open) load(); });
    const pause = () => {
        window.clearTimeout(timer);
        activeRequest?.abort();
        activeRequest = null;
    };
    document.addEventListener('visibilitychange', () => document.hidden ? pause() : schedule(1000));
    window.addEventListener('pagehide', () => { pageActive = false; pause(); });
    window.addEventListener('pageshow', () => { pageActive = true; schedule(1000); });
    schedule(1000);
})();
