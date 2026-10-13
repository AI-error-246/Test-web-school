(() => {
    'use strict';

    const initializeInterface = () => {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const mobileNavigation = window.matchMedia('(max-width: 1200px)');
        const header = document.querySelector('.site-header');
        const progress = document.querySelector('.navigation-progress');
        let progressTimeout;

        const clearProgress = () => {
            progress?.classList.remove('is-active');
            window.clearTimeout(progressTimeout);
            document.querySelectorAll('[data-submitting]').forEach((form) => {
                form.removeAttribute('data-submitting');
                form.removeAttribute('aria-busy');
            });
        };

        const showProgress = () => {
            if (!progress) {
                return;
            }
            progress.classList.add('is-active');
            window.clearTimeout(progressTimeout);
            // A download or failed navigation must not leave a permanent progress bar.
            progressTimeout = window.setTimeout(clearProgress, 12000);
        };

        document.querySelectorAll('.nav-toggle[aria-controls]').forEach((toggle) => {
            const navigation = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!navigation) {
                return;
            }

            const closeNavigation = (restoreFocus = false) => {
                toggle.setAttribute('aria-expanded', 'false');
                navigation.classList.remove('is-open');
                if (restoreFocus) {
                    toggle.focus();
                }
            };

            closeNavigation();
            toggle.addEventListener('click', () => {
                const expanded = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.setAttribute('aria-expanded', String(expanded));
                navigation.classList.toggle('is-open', expanded);
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                    closeNavigation(true);
                }
            });
            document.addEventListener('click', (event) => {
                if (toggle.getAttribute('aria-expanded') === 'true'
                    && !navigation.contains(event.target) && !toggle.contains(event.target)) {
                    closeNavigation(navigation.contains(document.activeElement));
                }
            });
            navigation.addEventListener('click', (event) => {
                if (event.target.closest('a') && mobileNavigation.matches) {
                    closeNavigation();
                }
            });
            mobileNavigation.addEventListener('change', () => {
                closeNavigation(mobileNavigation.matches && navigation.contains(document.activeElement));
            });
        });

        // Only collapse mobile navigation after its controls have been initialized.
        document.documentElement.classList.add('js');

        document.querySelectorAll('.language-switcher').forEach((form) => {
            const select = form.querySelector('select[name="locale"]');
            if (!(form instanceof HTMLFormElement) || !(select instanceof HTMLSelectElement)) {
                return;
            }
            select.addEventListener('change', () => {
                if (form.hasAttribute('data-submitting') || select.value === document.documentElement.lang) {
                    return;
                }
                const destination = form.querySelector('[name="return_to"]');
                if (destination) {
                    destination.value = location.pathname + location.search + location.hash;
                }
                // Keep the native POST, CSRF protection and server-rendered translations.
                form.requestSubmit();
            });
            const hint = form.querySelector('[data-language-hint]');
            if (hint) {
                hint.hidden = false;
                select.setAttribute('aria-describedby', hint.id);
            }
            // Leave the submit button available if JavaScript is unavailable.
            form.classList.add('is-enhanced');
        });

        const hero = document.querySelector('.landing-hero');
        const careerBoard = hero?.querySelector('.career-board');
        const careerNote = hero?.querySelector('.career-note');
        const process = document.querySelector('.process-section');
        const processSteps = [...document.querySelectorAll('.process-step')];
        const finalCta = document.querySelector('.final-cta');
        const clamp = (value) => Math.min(1, Math.max(0, value));
        const sections = [hero, process, finalCta].filter(Boolean);
        const visibleSections = new Set('IntersectionObserver' in window ? [] : sections);
        const styleValues = new WeakMap();
        let scrollFrame = 0;
        let pageActive = true;
        let wasScrolled;
        document.documentElement.classList.toggle('motion-paused', document.hidden);

        const setMotionStyle = (element, property, value) => {
            if (!element) {
                return;
            }
            let previous = styleValues.get(element);
            if (!previous) {
                previous = new Map();
                styleValues.set(element, previous);
            }
            if (previous.get(property) !== value) {
                element.style.setProperty(property, value);
                previous.set(property, value);
            }
        };

        const updateScrollEffects = () => {
            scrollFrame = 0;
            // Read section bounds together, before changing any classes or styles.
            const scrolled = window.scrollY > 10;
            const motionEnabled = !reducedMotion.matches;
            const viewportHeight = window.innerHeight;
            const distanceScale = window.innerWidth <= 680 ? 0.45 : 1;
            const heroBounds = motionEnabled && visibleSections.has(hero) ? hero.getBoundingClientRect() : null;
            const processBounds = motionEnabled && visibleSections.has(process) ? process.getBoundingClientRect() : null;
            const ctaBounds = motionEnabled && visibleSections.has(finalCta) ? finalCta.getBoundingClientRect() : null;
            const heroProgress = heroBounds ? clamp(-heroBounds.top / Math.max(heroBounds.height, 1)) : 0;
            const processProgress = processBounds
                ? clamp((viewportHeight - processBounds.top) / (viewportHeight + processBounds.height)) : 1;
            const ctaProgress = ctaBounds
                ? clamp((viewportHeight - ctaBounds.top) / (viewportHeight + ctaBounds.height)) : 0;

            if (wasScrolled !== scrolled) {
                header?.classList.toggle('is-scrolled', scrolled);
                wasScrolled = scrolled;
            }
            if (heroBounds || !motionEnabled) {
                setMotionStyle(careerBoard, '--scroll-y', `${(-32 * heroProgress * distanceScale).toFixed(2)}px`);
                setMotionStyle(careerBoard, '--scroll-turn', `${(2 * heroProgress * distanceScale).toFixed(2)}deg`);
                setMotionStyle(careerNote, '--scroll-y', `${(18 * heroProgress * distanceScale).toFixed(2)}px`);
                setMotionStyle(careerNote, '--scroll-turn', `${(-2 * heroProgress * distanceScale).toFixed(2)}deg`);
            }
            if (processBounds || !motionEnabled) {
                processSteps.forEach((step, index) => {
                    const travel = (1 - processProgress) * (12 + Math.min(index, 2) * 6) * distanceScale;
                    setMotionStyle(step, '--scroll-y', `${travel.toFixed(2)}px`);
                });
            }
            if (ctaBounds || !motionEnabled) {
                setMotionStyle(finalCta, '--scroll-turn', `${(ctaProgress * 28 * distanceScale).toFixed(2)}deg`);
            }
        };

        const scheduleScrollEffects = () => {
            if (!scrollFrame && pageActive && !document.hidden) {
                scrollFrame = window.requestAnimationFrame(updateScrollEffects);
            }
        };
        const pauseScrollEffects = () => {
            window.cancelAnimationFrame(scrollFrame);
            scrollFrame = 0;
        };
        if ('IntersectionObserver' in window && sections.length) {
            const motionObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.target === hero) {
                        hero.classList.toggle('is-motion-paused', !entry.isIntersecting);
                    }
                    if (entry.isIntersecting) {
                        visibleSections.add(entry.target);
                    } else {
                        visibleSections.delete(entry.target);
                    }
                });
                scheduleScrollEffects();
            }, { rootMargin: '80px' });
            sections.forEach(section => motionObserver.observe(section));
        }
        scheduleScrollEffects();
        window.addEventListener('scroll', scheduleScrollEffects, { passive: true });
        window.addEventListener('resize', scheduleScrollEffects, { passive: true });
        window.addEventListener('pageshow', () => {
            pageActive = true;
            document.documentElement.classList.toggle('motion-paused', document.hidden);
            scheduleScrollEffects();
        });
        window.addEventListener('pagehide', () => {
            pageActive = false;
            document.documentElement.classList.add('motion-paused');
            pauseScrollEffects();
        });
        document.addEventListener('visibilitychange', () => {
            document.documentElement.classList.toggle('motion-paused', document.hidden);
            if (document.hidden) {
                pauseScrollEffects();
            } else {
                scheduleScrollEffects();
            }
        });
        reducedMotion.addEventListener('change', scheduleScrollEffects);

        if ('IntersectionObserver' in window && !reducedMotion.matches) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-revealed');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.08 });
            document.querySelectorAll('.reveal').forEach((element) => {
                const siblings = [...element.parentElement.children].filter((child) => child.classList.contains('reveal'));
                element.style.setProperty('--reveal-delay', `${Math.min(siblings.indexOf(element), 3) * 20}ms`);
                observer.observe(element);
            });
        }

        document.querySelectorAll('[data-password-toggle][aria-controls]').forEach((toggle) => {
            const input = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            toggle.setAttribute('aria-pressed', 'false');
            toggle.addEventListener('click', () => {
                const visible = input.type === 'password';
                input.type = visible ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(visible));
                toggle.textContent = visible ? toggle.dataset.hideLabel : toggle.dataset.showLabel;
                toggle.setAttribute('aria-label', visible ? toggle.dataset.hideAria : toggle.dataset.showAria);
            });
        });

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
                || event.shiftKey || event.altKey || link.hasAttribute('download')
                || (link.target && link.target !== '_self')) {
                return;
            }
            const destination = new URL(link.href, window.location.href);
            const sameDocument = destination.pathname === location.pathname && destination.search === location.search;
            if (destination.origin === location.origin && !sameDocument && /^https?:$/.test(destination.protocol)) {
                showProgress();
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
                return;
            }
            const confirmation = event.submitter?.getAttribute('data-confirm') || form.getAttribute('data-confirm');
            if (confirmation && !window.confirm(confirmation)) {
                event.preventDefault();
                return;
            }
            // Do not disable the submitter: its name/value can carry a business action.
            form.setAttribute('data-submitting', 'true');
            form.setAttribute('aria-busy', 'true');
            if (!form.target || form.target === '_self') {
                showProgress();
            }
        });

        window.addEventListener('pageshow', clearProgress);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeInterface, { once: true });
    } else {
        initializeInterface();
    }
})();
