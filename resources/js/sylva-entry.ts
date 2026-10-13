const host = document.querySelector<HTMLElement>('[data-sylva-host]');
const status = document.querySelector<HTMLElement>('[data-sylva-status]');

if (host && status) {
    let unmount: (() => void) | undefined;
    let pending = false;
    let pageActive = true;
    let inView = false;
    let idleHandle: number | undefined;
    let timer: number | undefined;
    const messages = { loading: status.dataset.loading!, error: status.dataset.error! };

    const start = async () => {
        if (pending || unmount || !inView || !pageActive || document.hidden) return;
        pending = true;
        status.textContent = messages.loading;
        status.hidden = false;
        try {
            const { mountScene } = await import('./sylva-scene');
            if (!inView || !pageActive || document.hidden) return;
            unmount = mountScene(host);
            host.dataset.mounted = 'true';
            status.hidden = true;
        } catch {
            status.textContent = messages.error;
        } finally {
            pending = false;
        }
    };
    const schedule = () => {
        if ('requestIdleCallback' in window) {
            idleHandle = window.requestIdleCallback(start, { timeout: 600 });
        } else {
            timer = window.setTimeout(start, 100);
        }
    };
    const cancelScheduled = () => {
        if (idleHandle !== undefined) window.cancelIdleCallback?.(idleHandle);
        window.clearTimeout(timer);
    };
    const stop = () => {
        cancelScheduled();
        unmount?.();
        unmount = undefined;
        host.dataset.mounted = 'false';
    };
    // Delay the heavy authored scene until the existing page has painted.
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(([entry]) => {
            inView = entry?.isIntersecting ?? false;
            if (inView) schedule();
            else cancelScheduled();
        });
        observer.observe(host);
    } else {
        inView = true;
        schedule();
    }
    // The original component owns offscreen/tab pausing; only unload the React root on navigation.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !unmount) schedule();
    });
    window.addEventListener('pagehide', () => { pageActive = false; stop(); });
    window.addEventListener('pageshow', () => { pageActive = true; if (!unmount) schedule(); });
}
