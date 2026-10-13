(() => {
    'use strict';
    const widget = document.querySelector('[data-support-widget]');
    if (!widget) return;
    const panel = widget.querySelector('.support-panel');
    const launcher = widget.querySelector('.support-launcher');
    const form = widget.querySelector('.support-form');
    const input = form.querySelector('textarea');
    const submit = form.querySelector('[type="submit"]');
    const messages = widget.querySelector('.support-messages');
    const welcome = widget.querySelector('[data-support-welcome]').textContent;
    const status = widget.querySelector('.support-status');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let request = null;

    const appendMessage = (text, kind) => {
        const item = document.createElement('div');
        item.className = `support-message ${kind === 'user' ? 'is-user' : kind === 'error' ? 'is-error' : ''}`;
        item.setAttribute('aria-label', kind === 'user' ? widget.dataset.you : widget.dataset.bot);
        item.textContent = text;
        if (!panel.hidden && !reducedMotion.matches) {
            item.classList.add('is-new');
            item.addEventListener('animationend', () => item.classList.remove('is-new'), { once: true });
        }
        messages.append(item);
        while (messages.children.length > 16) messages.firstElementChild.remove();
        messages.scrollTop = messages.scrollHeight;
        return item;
    };
    const toggle = (open) => {
        if (!open) messages.querySelectorAll('.is-new').forEach(item => item.classList.remove('is-new'));
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', String(open));
        if (open) input.focus(); else launcher.focus();
    };
    launcher.addEventListener('click', () => toggle(panel.hidden));
    widget.querySelector('[data-support-close]').addEventListener('click', () => toggle(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); toggle(false); }
    });
    widget.querySelector('[data-support-clear]').addEventListener('click', () => {
        request?.abort();
        request = null;
        messages.replaceChildren();
        appendMessage(welcome, 'assistant');
        status.textContent = '';
        submit.disabled = false;
        form.removeAttribute('aria-busy');
        input.value = '';
        input.focus();
    });
    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) messages.querySelectorAll('.is-new').forEach(item => item.classList.remove('is-new'));
    });
    widget.querySelectorAll('[data-support-question]').forEach(button => {
        button.addEventListener('click', () => {
            if (request) return;
            input.value = button.dataset.supportQuestion;
            input.focus();
            form.requestSubmit();
        });
    });

    const appendJobs = (item, jobs) => {
        if (!Array.isArray(jobs) || jobs.length === 0) return;
        const list = document.createElement('div');
        list.className = 'support-jobs';
        const label = document.createElement('strong');
        label.textContent = widget.dataset.results;
        list.append(label);
        for (const job of jobs.slice(0, 6)) {
            let url;
            try { url = new URL(job.url, location.origin); } catch { continue; }
            if (url.origin !== location.origin || !/^\/jobs\/\d+$/.test(url.pathname)) continue;
            const link = document.createElement('a');
            link.className = 'support-job';
            link.href = url.href;
            const title = document.createElement('strong');
            title.textContent = job.title;
            const detail = document.createElement('small');
            detail.textContent = [job.company, job.location, widget.dataset[String(job.work_mode).toLowerCase()]].filter(Boolean).join(' · ');
            link.append(title, detail);
            list.append(link);
        }
        item.append(list);
        messages.scrollTop = messages.scrollHeight;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (request || !input.value.trim()) return;
        const question = input.value.trim().slice(0, 1200);
        appendMessage(question, 'user');
        input.value = '';
        submit.disabled = true;
        form.setAttribute('aria-busy', 'true');
        status.textContent = widget.dataset.loading;
        const controller = new AbortController();
        request = controller;
        const timeout = window.setTimeout(() => controller.abort(), Math.max(15000, Math.min(40000, Number(widget.dataset.timeoutMs) || 30000)));
        try {
            const response = await fetch(widget.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value },
                body: JSON.stringify({ message: question }),
            });
            const data = await response.json();
            if (request !== controller) return;
            if (!response.ok || typeof data.answer !== 'string') {
                appendMessage(typeof data.message === 'string' ? data.message : widget.dataset.error, 'error');
                if (!input.value) input.value = question;
                return;
            }
            const answer = data.answer.slice(0, 8000);
            const item = appendMessage(answer, 'assistant');
            appendJobs(item, data.jobs);
        } catch {
            if (request === controller) {
                appendMessage(widget.dataset.error, 'error');
                if (!input.value) input.value = question;
            }
        } finally {
            window.clearTimeout(timeout);
            if (request === controller) {
                request = null;
                submit.disabled = false;
                form.removeAttribute('aria-busy');
                status.textContent = '';
            }
        }
    });
    widget.hidden = false;
})();
