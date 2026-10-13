import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const base = process.env.SYLVA_TEST_BASE || 'http://127.0.0.1:3002';
assert.ok(/^http:\/\/127\.0\.0\.1:300[12]$/.test(base), 'Use the existing local application or isolated demo only');
const output = fileURLToPath(new URL(`../../../../06_Testing/full-world-theme/${base.endsWith('3001') ? 'live/' : ''}`, import.meta.url));
await mkdir(output, { recursive: true });
const report = { at: new Date().toISOString(), base, mode: base.endsWith('3002') ? 'ISOLATED_SIMULATION' : 'PUBLIC_READ_ONLY', status: 'running', checks: [], errors: [], failedAssets: [], measurements: {} };
const headless = process.env.SYLVA_TEST_HEADLESS !== '0';
report.headless = headless;
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless, defaultViewport: { width: 1440, height: 1000, deviceScaleFactor: 1 }, args: ['--no-first-run', '--window-size=1480,1050'] });
const page = await browser.newPage();
page.setDefaultTimeout(25000);
page.on('pageerror', error => report.errors.push(error.message));
page.on('console', message => {
    if (message.type() === 'error') report.errors.push(message.text());
});
page.on('response', response => {
    if (response.status() >= 400 && new URL(response.url()).origin === base) report.failedAssets.push({ url: response.url(), status: response.status() });
});
const networkURLs = new Set();
page.on('request', request => networkURLs.add(request.url()));

// Instrument only the browser test realm; the registered source stays byte-identical.
// Capturing immediately after render also works with preserveDrawingBuffer=false.
await page.evaluateOnNewDocument(() => {
    if (window === window.top) {
        const trace = window.__sylvaScrollTrace = [];
        const record = type => {
            trace.push({ type, at: Math.round(performance.now()), scrollY, focus: document.activeElement?.tagName, navigation: performance.getEntriesByType('navigation')[0]?.type });
            if (trace.length > 80) trace.shift();
        };
        for (const type of ['scroll', 'load', 'pageshow']) window.addEventListener(type, () => record(type), { passive: true });
        window.addEventListener('wheel', event => record(`wheel:${event.deltaY}:trusted=${event.isTrusted}`), { passive: true });
        window.addEventListener('keydown', event => record(`keydown:trusted=${event.isTrusted}`));
        document.addEventListener('focusin', () => record('focusin'));
        return;
    }
    const probe = window.__sylvaProbe = { documentId: `${performance.timeOrigin}:${Math.random()}`, renders: 0, pointerEvents: 0, captureRequested: false, captured: null };
    window.addEventListener('pointermove', () => probe.pointerEvents++, { passive: true });
    let library;
    Object.defineProperty(window, 'THREE', {
        configurable: true,
        get: () => library,
        set(value) {
            library = value;
            let constructor;
            Object.defineProperty(value, 'WebGLRenderer', {
                configurable: true,
                enumerable: true,
                get: () => constructor,
                set(Renderer) {
                    constructor = new Proxy(Renderer, {
                        construct(Target, args) {
                            const renderer = Reflect.construct(Target, args);
                            const render = renderer.render;
                            renderer.render = function (scene, camera) {
                                const result = render.call(this, scene, camera);
                                probe.renderer = this;
                                probe.scene = scene;
                                probe.camera = camera;
                                probe.renders++;
                                if (probe.captureRequested) {
                                    probe.captureRequested = false;
                                    const gl = this.getContext();
                                    const width = gl.drawingBufferWidth;
                                    const height = gl.drawingBufferHeight;
                                    const pixels = new Uint8Array(width * height * 4);
                                    gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
                                    let painted = 0;
                                    let hash = 2166136261;
                                    for (let i = 0; i < pixels.length; i += 16) {
                                        if (pixels[i + 3]) painted++;
                                        hash = Math.imul(hash ^ pixels[i], 16777619);
                                        hash = Math.imul(hash ^ pixels[i + 1], 16777619);
                                    }
                                    probe.captured = { width, height, painted, hash: hash >>> 0 };
                                }
                                return result;
                            };
                            return renderer;
                        },
                    });
                },
            });
        },
    });
});

const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const sceneSelector = '[data-sylva-host] .sylva-living-world-scene[data-variant="living-green"]';
const iframeSelector = sceneSelector + ' iframe';
const go = async path => {
    const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
    assert.equal(response.status(), 200);
};
const sceneFrame = async ({ scanned = true } = {}) => {
    const deadline = Date.now() + 45000;
    while (Date.now() < deadline) {
        try {
            await page.waitForSelector(iframeSelector);
            const handle = await page.$(iframeSelector);
            if (!handle) continue;
            const frame = await handle.contentFrame();
            await handle.dispose();
            if (!frame) continue;
            await frame.waitForFunction(() => window.__sylvaProbe?.renders > 0);
            if (scanned) {
                await frame.waitForFunction(() => window.__sylvaProbe.scene.children.some(group => group.userData?.blades > 0 && group.userData.uni.uScanOn.value === 0));
            }
            return frame;
        } catch (error) {
            // Visibility changes intentionally destroy the old document. Only retry
            // that lifecycle race; actual build, shader and timeout failures remain failures.
            if (!/detached|Execution context was destroyed|Cannot find context|Unable to adopt element handle/i.test(error.message)) throw error;
            await pause(100);
        }
    }
    throw new Error('No stable rendered Sylva iframe after visibility change');
};
const snapshot = frame => frame.evaluate(() => {
    const probe = window.__sylvaProbe;
    const roots = probe.scene.children.filter(group => group.userData?.blades > 0);
    const near = roots.reduce((best, group) => !best || group.userData.blades > best.userData.blades ? group : best, null);
    const butterfly = near.children.find(child => child.isGroup);
    return {
        documentId: probe.documentId,
        revision: window.THREE.REVISION,
        renders: probe.renders,
        calls: probe.renderer.info.render.calls,
        triangles: probe.renderer.info.render.triangles,
        points: probe.renderer.info.render.points,
        roots: roots.length,
        blades: roots.map(group => group.userData.blades),
        time: near.userData.uni.uTime.value,
        mouse: near.userData.uni.uMouse.value.toArray(),
        pointerEvents: probe.pointerEvents,
        butterfly: butterfly?.position.toArray() ?? null,
        width: innerWidth,
        height: innerHeight,
    };
});
const layoutSnapshot = () => page.evaluate(async () => {
    const bounds = selector => {
        const element = document.querySelector(selector);
        if (!element) return null;
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return { top: rect.top, left: rect.left, bottom: rect.bottom, width: rect.width, height: rect.height, display: style.display, visibility: style.visibility, position: style.position };
    };
    const host = document.querySelector('[data-sylva-host]');
    const intersection = host && !document.hidden && typeof IntersectionObserver !== 'undefined' ? await new Promise(resolve => {
        const observer = new IntersectionObserver(([entry]) => {
            observer.disconnect();
            resolve({ intersecting: entry.isIntersecting, ratio: entry.intersectionRatio });
        });
        observer.observe(host);
    }) : null;
    return {
        scrollY,
        viewportWidth: document.documentElement.clientWidth,
        viewportHeight: innerHeight,
        hidden: document.hidden,
        hostInsideHero: Boolean(host?.closest('.landing-hero')),
        heroScrollTop: document.querySelector('.sylva-hero')?.scrollTop,
        hero: bounds('.sylva-hero'),
        world: bounds('.sylva-world'),
        host: bounds('[data-sylva-host]'),
        intersection,
        component: bounds('.sylva-living-world-scene'),
        iframe: bounds('.sylva-living-world-scene iframe'),
        mounted: document.querySelector('[data-sylva-host]')?.dataset.mounted,
        status: document.querySelector('[data-sylva-status]')?.textContent,
        scrollTrace: window.__sylvaScrollTrace,
    };
});

const assertFullViewport = layout => {
    assert.equal(layout.world.position, 'fixed');
    assert.ok(Math.abs(layout.world.top) <= 2 && Math.abs(layout.world.left) <= 2);
    assert.ok(Math.abs(layout.world.width - layout.viewportWidth) <= 2);
    assert.ok(Math.abs(layout.world.height - layout.viewportHeight) <= 2);
    assert.equal(layout.hostInsideHero, false, 'The world must not remain inside the old hero box');
    assert.ok(Math.abs(layout.host.top) <= 2 && Math.abs(layout.host.left) <= 2);
    assert.ok(Math.abs(layout.host.width - layout.viewportWidth) <= 2);
    assert.ok(Math.abs(layout.host.height - layout.viewportHeight) <= 2);
    assert.ok(Math.abs(layout.iframe.width - layout.viewportWidth) <= 2);
    assert.ok(Math.abs(layout.iframe.height - layout.viewportHeight) <= 2);
};
const appearance = () => page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    saved: localStorage.getItem('internbridge-theme'),
    timeOrigin: performance.timeOrigin,
    colors: [getComputedStyle(document.body).color, getComputedStyle(document.body).backgroundColor, getComputedStyle(document.querySelector('.site-header')).backgroundColor],
}));
const sourceHashes = {
    'src/shaders/sylva-living-world/SylvaLivingWorldScene.tsx': 'e29b92a16596bc9383e1dbd4630e83b70ec2a59dbae48de1d3b7ddc48c0b2082',
    'src/shaders/sylva-living-world/sources/inner-green-3d.html': '69c3694bd63f44ef9f007ebe4dac57a83e4402e0cdf6b54dd10b96dd4f05e197',
    'src/shaders/sylva-living-world/sources/inner-green-assets/three.min.js': '8a5f7249903b54d30f79f708699d2fed2d6a1d0741a4cd41377d1f01bb5a2271',
    'src/shaders/threeui.css': 'efe4447139f1358dd8e9be68edf6fa46cbefbd1de423a4d6c439ca61d2c8eccf',
};

try {
    report.phase = 'registered-source';
    for (const [path, expected] of Object.entries(sourceHashes)) {
        const bytes = await readFile(new URL(`../../resources/vendor/threeui/${path}`, import.meta.url));
        assert.equal(createHash('sha256').update(bytes).digest('hex'), expected, `The registered source must stay byte-identical: ${path}`);
    }
    report.checks.push('All four registered ThreeUI source files retain the supplied SHA-256 checksums');

    report.phase = 'desktop-render';
    await go('/');
    report.measurements.scrollAfterNavigation = await page.evaluate(() => window.scrollY);
    assert.ok(await page.$('body.is-home'));
    assert.equal(await page.$('[data-sylva-toggle]'), null, 'The removed pause control must not return');
    assert.ok(await page.$('[data-theme-toggle]'));
    let frame = await sceneFrame();
    assert.equal(await page.$eval(iframeSelector, iframe => iframe.getAttribute('sandbox')), 'allow-scripts');
    assert.equal(await page.$eval(iframeSelector, iframe => iframe.getAttribute('src')), null);
    report.measurements.scrollAfterScene = await page.evaluate(() => window.scrollY);
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.waitForFunction(() => window.scrollY === 0);
    const desktopLayout = await layoutSnapshot();
    assertFullViewport(desktopLayout);
    const first = await snapshot(frame);
    assert.equal(first.revision, '149');
    assert.equal(first.roots, 2);
    assert.ok(first.triangles > 10000 && first.calls > 0, 'The procedural scene must actually render geometry');
    assert.ok(first.points > 0, 'Pollen must be drawn');
    assert.ok(first.width > 900 && first.width * first.height >= 620000, 'Desktop frame must keep the authored butterfly enabled');
    assert.ok(first.butterfly, 'Desktop must contain the authored butterfly');
    await frame.evaluate(() => { window.__sylvaProbe.captureRequested = true; });
    await frame.waitForFunction(() => window.__sylvaProbe.captured !== null);
    const pixels = await frame.evaluate(() => window.__sylvaProbe.captured);
    assert.ok(pixels.painted > 500, 'The rendered frame must contain visible pixels');
    report.measurements.desktop = { ...first, pixels, layout: desktopLayout };
    report.measurements.scrollBeforeDesktopScreenshot = await page.evaluate(() => window.scrollY);
    await page.screenshot({ path: output + 'desktop.png' });
    report.checks.push('Full-viewport living-green world draws both roots, pollen and butterfly with Three.js r149 inside the original sandbox');

    report.phase = 'pointer-interaction';
    const pointerSpot = await page.evaluate(selector => {
        const iframe = document.querySelector(selector);
        for (const x of [innerWidth - 25, 25, innerWidth * .83, innerWidth * .7]) {
            for (const y of [300, innerHeight * .5, innerHeight * .62, innerHeight * .82]) {
                if (document.elementFromPoint(x, y) === iframe) return { x, y };
            }
        }
        return null;
    }, iframeSelector);
    assert.ok(pointerSpot, 'Some uncovered background must remain available for the authored pointer interaction');
    await page.mouse.move(pointerSpot.x, pointerSpot.y, { steps: 12 });
    await page.mouse.move(pointerSpot.x, pointerSpot.y + 12, { steps: 5 });
    await frame.waitForFunction(() => {
        const probe = window.__sylvaProbe;
        return probe.pointerEvents > 0 && probe.scene.children.some(group => group.userData?.uni?.uMouse?.value.x < 999);
    });
    const pointed = await snapshot(frame);
    assert.notDeepEqual(pointed.mouse, first.mouse, 'Pointer must reach the authored moss shader');
    await frame.waitForFunction(previous => {
        const near = window.__sylvaProbe.scene.children.filter(group => group.userData?.blades > 0).sort((a, b) => b.userData.blades - a.userData.blades)[0];
        return near.children.find(child => child.isGroup).position.toArray().some((value, index) => Math.abs(value - previous[index]) > .01);
    }, {}, first.butterfly);
    await page.screenshot({ path: output + 'pointer-interaction.png' });
    report.measurements.pointer = pointed;
    report.checks.push('Real pointer motion reaches moss uniforms and parallax; the authored butterfly changes position');

    report.phase = 'full-page-scroll';
    const beforeScroll = await snapshot(frame);
    await page.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' }));
    await frame.waitForFunction(previous => window.__sylvaProbe.renders > previous + 2, {}, beforeScroll.renders);
    const bottom = await snapshot(frame);
    const bottomLayout = await layoutSnapshot();
    assertFullViewport(bottomLayout);
    assert.equal(bottom.documentId, beforeScroll.documentId, 'Scrolling the homepage must retain the same scene document');
    assert.ok(bottom.time > beforeScroll.time);
    assert.ok(bottomLayout.scrollY > 500);
    await page.screenshot({ path: output + 'desktop-bottom.png' });
    report.measurements.bottom = { ...bottom, layout: bottomLayout };
    report.checks.push('Homepage world remains fixed across the entire scroll with the same iframe and an advancing animation clock');

    report.phase = 'theme-without-scene-reset';
    const beforeAppearance = await appearance();
    const beforeTheme = await snapshot(frame);
    const expectedTheme = beforeAppearance.theme === 'dark' ? 'light' : 'dark';
    await page.click('[data-theme-toggle]');
    await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, {}, expectedTheme);
    await frame.waitForFunction(previous => window.__sylvaProbe.renders > previous + 2, {}, beforeTheme.renders);
    const afterAppearance = await appearance();
    const afterTheme = await snapshot(frame);
    assert.equal(afterAppearance.saved, expectedTheme);
    assert.equal(await page.$eval('[data-theme-toggle]', element => element.getAttribute('aria-pressed')), String(expectedTheme === 'dark'));
    assert.equal(afterAppearance.timeOrigin, beforeAppearance.timeOrigin, 'Appearance switching must not reload the page');
    assert.notDeepEqual(afterAppearance.colors, beforeAppearance.colors, 'Appearance switching must visibly change page colors');
    assert.equal(afterTheme.documentId, beforeTheme.documentId, 'Theme change must retain the existing WebGL scene');
    assert.ok(afterTheme.time > beforeTheme.time);
    report.measurements.theme = { before: beforeAppearance, after: afterAppearance, sceneBefore: beforeTheme.time, sceneAfter: afterTheme.time };
    await page.screenshot({ path: output + 'alternate-theme-bottom.png' });
    report.checks.push('Appearance toggle changes colors and saves the preference without navigation or restarting WebGL');

    report.phase = 'hidden-tab-unmount';
    const otherTab = await browser.newPage();
    await otherTab.goto('about:blank');
    await otherTab.bringToFront();
    await page.waitForFunction(selector => document.hidden && document.querySelector(selector) === null, { polling: 100 }, iframeSelector);
    await page.bringToFront();
    frame = await sceneFrame();
    await otherTab.close();
    report.checks.push('Original component removes the iframe in a hidden tab and renders again when the homepage becomes visible');

    report.phase = 'reduced-motion';
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.reload({ waitUntil: 'networkidle0' });
    frame = await sceneFrame();
    const still = await snapshot(frame);
    await page.mouse.move(25, 620, { steps: 5 });
    await pause(600);
    const afterStill = await snapshot(frame);
    assert.equal(afterStill.renders, still.renders, 'Reduced motion must not redraw a continuous WebGL loop');
    assert.equal(afterStill.time, still.time);
    assert.equal(afterStill.time, 0);
    await page.screenshot({ path: output + 'reduced-motion.png' });
    report.measurements.reducedMotion = afterStill;
    report.checks.push('Reduced motion renders a static scene without relying on the canonical second-frame ready flag');

    report.phase = 'mobile-layout';
    // Switching isMobile can reload the previous desktop document. Start a new
    // mobile visit so history restoration from that document cannot race this case.
    await page.goto('about:blank');
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'no-preference' }]);
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 });
    await go('/');
    report.measurements.mobileAfterNavigation = await layoutSnapshot();
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.waitForFunction(() => window.scrollY === 0);
    report.measurements.mobileBeforeTopScreenshot = await layoutSnapshot();
    frame = await sceneFrame();
    report.measurements.mobileAfterMount = await layoutSnapshot();
    assertFullViewport(report.measurements.mobileAfterMount);
    assert.ok(Math.abs(report.measurements.mobileAfterMount.scrollY) <= 3, 'Mounting the decorative scene must not shift the mobile page scroll position');
    const mobile = await snapshot(frame);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.ok(mobile.width <= 390);
    assert.equal(mobile.butterfly, null, 'The authored narrow layout deliberately omits the butterfly');
    assert.ok(Math.abs(mobile.height - 844) <= 2);
    await page.screenshot({ path: output + 'mobile-top.png' });
    await page.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' }));
    const mobileBottom = await layoutSnapshot();
    assertFullViewport(mobileBottom);
    assert.equal((await snapshot(frame)).documentId, mobile.documentId);
    await page.screenshot({ path: output + 'mobile-bottom.png' });
    report.measurements.mobile = mobile;
    report.checks.push('390px mobile keeps the world fixed across the viewport and retains the same scene while scrolling without overflow');

    report.phase = 'locale-and-search';
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.select('#site-language', 'ja')]);
    assert.equal(await page.$eval('html', element => element.lang), 'ja');
    await page.type('#hero-query', 'Laravel');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('.hero-search button')]);
    assert.equal(new URL(page.url()).pathname, '/jobs');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Laravel');
    assert.equal(await page.$eval('html', element => element.lang), 'ja');
    assert.equal(await page.$('[data-sylva-host]'), null);
    assert.equal(await page.$('script[src*="sylva"]'), null);
    assert.equal((await appearance()).theme, expectedTheme);
    assert.ok(await page.$('body.has-sparkles .ambient-sparkles'));
    assert.equal(await page.$eval('.ambient-sparkles', element => getComputedStyle(element).pointerEvents), 'none');
    assert.notEqual(await page.$eval('.ambient-sparkles', element => getComputedStyle(element, '::before').animationName), 'none');
    const themeLabels = {
        vi: { dark: 'Chuyển sang giao diện sáng', light: 'Chuyển sang giao diện tối' },
        en: { dark: 'Switch to light appearance', light: 'Switch to dark appearance' },
        ja: { dark: 'ライトモードに切り替え', light: 'ダークモードに切り替え' },
    };
    for (const locale of ['vi', 'en', 'ja']) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.select('#site-language', locale)]);
        assert.equal(await page.$eval('html', element => element.lang), locale);
        assert.equal((await appearance()).theme, expectedTheme);
        assert.equal((await appearance()).saved, expectedTheme);
        const label = await page.$eval('[data-theme-toggle]', element => element.getAttribute('aria-label'));
        assert.equal(label, themeLabels[locale][expectedTheme], 'Theme action must use the current locale and describe the next appearance');
    }
    await page.reload({ waitUntil: 'networkidle0' });
    assert.equal((await appearance()).theme, expectedTheme);
    await page.screenshot({ path: output + 'jobs-saved-theme.png' });
    report.checks.push('Search and VI/EN/JA language selection retain the saved appearance through navigation and reload; non-home pages use lightweight sparkles without the scene bundle');

    report.phase = 'sparkle-visibility-and-reduced-motion';
    const sparkleTab = await browser.newPage();
    await sparkleTab.goto('about:blank');
    await sparkleTab.bringToFront();
    await page.waitForFunction(() => document.hidden && document.body.classList.contains('is-page-hidden'), { polling: 100 });
    assert.equal(await page.$eval('.ambient-sparkles', element => getComputedStyle(element, '::before').animationPlayState), 'paused');
    await page.bringToFront();
    await page.waitForFunction(() => !document.body.classList.contains('is-page-hidden'));
    await sparkleTab.close();
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    assert.equal(await page.$eval('.ambient-sparkles', element => getComputedStyle(element, '::before').animationName), 'none');
    report.checks.push('Lightweight sparkles pause in hidden tabs and become static with reduced motion');

    assert.equal([...networkURLs].some(url => /^https?:\/\/(?:www\.)?threeui\.com/.test(url)), false, 'The application must render vendored source, not the ThreeUI website');
    assert.deepEqual(report.failedAssets, []);
    assert.deepEqual(report.errors, []);
    report.checks.push('No application asset failures, shader/JavaScript errors or ThreeUI documentation requests');
    report.status = 'passed';
    report.phase = 'complete';
} catch (error) {
    report.status = 'failed';
    report.failure = error.stack;
    report.measurements.failureLayout = await layoutSnapshot().catch(() => null);
    await page.screenshot({ path: output + 'failure.png', fullPage: true }).catch(() => {});
    process.exitCode = 1;
} finally {
    report.completedAt = new Date().toISOString();
    await writeFile(output + 'browser.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify({ status: report.status, checks: report.checks.length, failure: report.failure, errors: report.errors, failedAssets: report.failedAssets }));
    await browser.close();
}
