(() => {
    'use strict';

    const initializeParticles = () => {
        const hero = document.querySelector('.landing-hero');
        const canvas = hero?.querySelector('canvas.hero-particles');
        if (!(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const context = canvas.getContext('2d', { alpha: true });
        if (!context) {
            return;
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const precisePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
        const pointer = { clientX: 0, clientY: 0, active: false };
        let particles = [];
        let width = 0;
        let height = 0;
        let frame = 0;
        let previousFrame = 0;
        let lastMovement = 0;
        let settleUntil = 0;
        let needsResize = true;
        let needsBounds = true;
        let fieldBounds = null;
        let inViewport = true;
        let pageActive = true;

        const mayRender = () => pageActive && inViewport && !document.hidden;
        const mayAnimate = () => precisePointer.matches && !reducedMotion.matches;
        const random = (seed) => {
            const value = Math.sin(seed * 127.1 + 311.7) * 43758.5453;
            return value - Math.floor(value);
        };

        const resetPositions = () => {
            particles.forEach((particle) => {
                particle.x = particle.homeX;
                particle.y = particle.homeY;
                particle.vx = 0;
                particle.vy = 0;
            });
        };

        const resizeField = (bounds) => {
            width = Math.max(1, bounds.width);
            height = Math.max(1, bounds.height);
            const pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
            canvas.width = Math.round(width * pixelRatio);
            canvas.height = Math.round(height * pixelRatio);
            context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);

            const count = Math.min(210, Math.max(100, Math.round(width * height / 3900)));
            particles = Array.from({ length: count }, (_, index) => {
                let normalizedX = 0.025 + random(index + 1) * 0.95;
                const spread = (random(index + 93) - 0.5) * 0.16;
                let normalizedY;

                if (index % 5 < 2) {
                    normalizedY = 0.10 + Math.sin(normalizedX * 8) * 0.055 + spread;
                } else if (index % 5 < 4) {
                    normalizedY = 0.86 + Math.sin(normalizedX * 9 + 1) * 0.06 + spread;
                } else {
                    normalizedX = 0.73 + random(index + 51) * 0.24;
                    normalizedY = 0.16 + random(index + 171) * 0.67;
                }

                const homeX = normalizedX * width;
                const homeY = Math.max(0.025, Math.min(0.975, normalizedY)) * height;
                const opacity = 0.20 + random(index + 347) * 0.24;
                return {
                    homeX,
                    homeY,
                    x: homeX,
                    y: homeY,
                    vx: 0,
                    vy: 0,
                    radius: 0.7 + random(index + 281) * 0.85,
                    color: index % 3 === 0
                        ? `rgba(23, 125, 127, ${opacity})`
                        : `rgba(13, 107, 85, ${opacity})`,
                };
            });
            needsResize = false;
        };

        const drawField = () => {
            context.clearRect(0, 0, width, height);
            particles.forEach((particle) => {
                context.beginPath();
                context.fillStyle = particle.color;
                context.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
                context.fill();
            });
        };

        const stopFrames = () => {
            window.cancelAnimationFrame(frame);
            frame = 0;
            previousFrame = 0;
            pointer.active = false;
            settleUntil = 0;
            resetPositions();
        };

        const renderFrame = (time) => {
            frame = 0;
            if (!mayRender()) {
                return;
            }

            // Cap rendering on high-refresh displays without a permanent animation loop.
            if (previousFrame && time - previousFrame < 1000 / 60 - 0.5) {
                frame = window.requestAnimationFrame(renderFrame);
                return;
            }

            const timeStep = previousFrame ? Math.min((time - previousFrame) / (1000 / 60), 2) : 1;
            previousFrame = time;
            const moving = mayAnimate() && time < settleUntil;
            // Reuse geometry while particles settle; refresh only after an event changes it.
            if (needsResize || needsBounds) {
                fieldBounds = hero.getBoundingClientRect();
                needsBounds = false;
            }
            const bounds = fieldBounds;
            if (needsResize && bounds) {
                resizeField(bounds);
            }

            if (moving) {
                const pointerX = bounds ? pointer.clientX - bounds.left : 0;
                const pointerY = bounds ? pointer.clientY - bounds.top : 0;
                const activity = pointer.active ? Math.max(0, 1 - (time - lastMovement) / 280) : 0;
                const radius = Math.min(160, width * 0.3);
                const radiusSquared = radius * radius;
                const damping = Math.pow(0.84, timeStep);

                particles.forEach((particle) => {
                    let forceX = 0;
                    let forceY = 0;
                    if (activity > 0) {
                        const dx = particle.x - pointerX;
                        const dy = particle.y - pointerY;
                        const distanceSquared = dx * dx + dy * dy;
                        if (distanceSquared < radiusSquared) {
                            const distance = Math.max(1, Math.sqrt(distanceSquared));
                            const influence = Math.pow(1 - distance / radius, 2) * activity;
                            forceX = (dx * 1.1 - dy * 1.6) * influence / distance;
                            forceY = (dy * 1.1 + dx * 1.6) * influence / distance;
                        }
                    }
                    particle.vx += ((particle.homeX - particle.x) * 0.035 + forceX) * timeStep;
                    particle.vy += ((particle.homeY - particle.y) * 0.035 + forceY) * timeStep;
                    particle.vx *= damping;
                    particle.vy *= damping;
                    particle.x += particle.vx * timeStep;
                    particle.y += particle.vy * timeStep;
                });
            } else {
                resetPositions();
            }

            drawField();
            if (moving) {
                frame = window.requestAnimationFrame(renderFrame);
            } else {
                previousFrame = 0;
            }
        };

        const requestFrame = () => {
            if (!frame && mayRender()) {
                frame = window.requestAnimationFrame(renderFrame);
            }
        };

        hero.addEventListener('pointermove', (event) => {
            if (!mayAnimate() || event.pointerType === 'touch' || !mayRender()) {
                return;
            }
            pointer.clientX = event.clientX;
            pointer.clientY = event.clientY;
            pointer.active = true;
            needsBounds = true;
            lastMovement = performance.now();
            settleUntil = lastMovement + 1400;
            requestFrame();
        }, { passive: true });

        hero.addEventListener('pointerleave', () => {
            pointer.active = false;
            if (mayAnimate() && settleUntil > performance.now()) {
                settleUntil = performance.now() + 1000;
                requestFrame();
            }
        }, { passive: true });

        const updateMotionPreference = () => {
            stopFrames();
            needsBounds = true;
            requestFrame();
        };
        reducedMotion.addEventListener('change', updateMotionPreference);
        precisePointer.addEventListener('change', updateMotionPreference);

        window.addEventListener('scroll', () => {
            needsBounds = true;
        }, { passive: true });

        if ('ResizeObserver' in window) {
            const resizeObserver = new ResizeObserver(() => {
                needsResize = true;
                requestFrame();
            });
            resizeObserver.observe(hero);
        } else {
            window.addEventListener('resize', () => {
                needsResize = true;
                requestFrame();
            }, { passive: true });
        }

        if ('IntersectionObserver' in window) {
            const visibilityObserver = new IntersectionObserver(([entry]) => {
                inViewport = entry.isIntersecting;
                if (inViewport) {
                    needsBounds = true;
                    requestFrame();
                } else {
                    stopFrames();
                }
            });
            visibilityObserver.observe(hero);
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopFrames();
            } else {
                needsBounds = true;
                requestFrame();
            }
        });
        window.addEventListener('pagehide', () => {
            pageActive = false;
            stopFrames();
        });
        window.addEventListener('pageshow', () => {
            pageActive = true;
            needsResize = true;
            requestFrame();
        });

        requestFrame();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeParticles, { once: true });
    } else {
        initializeParticles();
    }
})();
