import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

const output = fileURLToPath(new URL('../../../../06_Testing/full-world-theme/navigation/', import.meta.url));
await mkdir(output, { recursive: true });
const report = { at: new Date().toISOString(), mode: 'ISOLATED_SIMULATION', checks: [], errors: [], measurements: {} };
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: process.env.UI_TEST_HEADLESS === '1', defaultViewport: { width: 1440, height: 950 }, args: ['--no-first-run', '--window-size=1480,1000'] });
const page = await browser.newPage();
page.on('pageerror', error => report.errors.push(error.message));
await page.evaluateOnNewDocument(() => {
    if (window === window.top) {
        try { localStorage.setItem('internbridge-theme', 'dark'); } catch {}
    }
    window.motionWork = { hero: 0, process: 0, cta: 0, writes: 0 };
    const bounds = Element.prototype.getBoundingClientRect;
    Element.prototype.getBoundingClientRect = function (...args) {
        if (this.matches('.landing-hero')) window.motionWork.hero++;
        if (this.matches('.process-section')) window.motionWork.process++;
        if (this.matches('.final-cta')) window.motionWork.cta++;
        return bounds.apply(this, args);
    };
    const set = CSSStyleDeclaration.prototype.setProperty;
    CSSStyleDeclaration.prototype.setProperty = function (name, ...args) {
        if (name.startsWith('--scroll-')) window.motionWork.writes++;
        return set.call(this, name, ...args);
    };
    window.addEventListener('pagereveal', event => { window.nativeTransition = Boolean(event.viewTransition); });
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const go = async path => {
    const response = await page.goto('http://127.0.0.1:3002' + path, { waitUntil: 'networkidle0' });
    assert.equal(response.status(), 200);
};
const click = selector => Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector)]);
const locale = async value => {
    assert.equal(await page.$eval('.language-switcher button', el => getComputedStyle(el).display), 'none');
    if (await page.$eval('html', el => el.lang) !== value) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.select('#site-language', value)]);
    }
    assert.equal(await page.$eval('html', el => el.lang), value);
    assert.equal(await page.$eval('#site-language', el => el.value), value);
};
const assertLocale = async value => assert.equal(await page.$eval('html', el => el.lang), value);
const resetWork = () => page.evaluate(() => { Object.keys(window.motionWork).forEach(key => { window.motionWork[key] = 0; }); });
try {
    await go('/jobs?q=Laravel#main-content');
    await locale('en');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Laravel');
    assert.equal(new URL(page.url()).hash, '#main-content');
    await click('.site-header .brand');
    await assertLocale('en');
    report.measurements.nativeTransition = await page.evaluate(() => ({ supported: 'CSSViewTransitionRule' in window, activeOnNavigation: window.nativeTransition }));
    if (report.measurements.nativeTransition.supported) assert.equal(report.measurements.nativeTransition.activeOnNavigation, true);
    await locale('ja');
    await page.goBack({ waitUntil: 'networkidle0' });
    await assertLocale('ja');
    await page.reload({ waitUntil: 'networkidle0' });
    await assertLocale('ja');
    report.checks.push('Auto-select preserves query/anchor; public menu, Back and reload keep chosen language; native transition runs when supported');

    for (const [role, value, paths] of [
        ['candidate', 'en', ['/candidate/profile/view', '/candidate/cvs', '/candidate/applications']],
        ['employer', 'ja', ['/employer/company', '/employer/jobs', '/employer/jobs/create']],
        ['admin', 'en', ['/admin/users?role=candidate', '/admin/jobs', '/admin/statistics']],
    ]) {
        await go(role === 'admin' ? '/admin/login' : '/login');
        await locale(value);
        await page.type('[name=email]', role + '@simulation.test');
        await page.type('[name=password]', 'Simulation-only-2026');
        await click(`form[action$="${role === 'admin' ? '/admin/login' : '/login'}"] button[type=submit]`);
        if (role === 'admin') {
            assert.equal(new URL(page.url()).pathname, '/admin/mfa');
            if (await page.$('form[action$="/mfa/enroll"]')) await click('form[action$="/mfa/enroll"] button');
            await page.type('[name=code]', '123456');
            await click('form[action$="/mfa/verify"] button');
        }
        assert.equal(new URL(page.url()).pathname, '/' + role);
        // Change inside the workspace as well, then follow its actual menu links.
        const selected = value === 'en' ? 'ja' : 'en';
        await locale(selected);
        for (const path of paths) {
            await click(`.workspace-nav a[href="http://127.0.0.1:3002${path}"]`);
            assert.equal(new URL(page.url()).pathname, path.split('?')[0]);
            await assertLocale(selected);
        }
        await page.screenshot({ path: output + role + '-desktop.png', fullPage: true });
        assert.equal(await page.$eval('html', element => element.dataset.theme), 'dark');
        await page.setViewport({ width: 390, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), role + ' mobile workspace must not overflow');
        assert.ok(await page.evaluate(() => {
            const brand = document.querySelector('.site-header .brand').getBoundingClientRect();
            const appearance = document.querySelector('[data-theme-toggle]').getBoundingClientRect();
            return appearance.left >= brand.right;
        }), role + ' mobile appearance control must not overlap the brand');
        await page.screenshot({ path: output + role + '-mobile.png', fullPage: true });
        await page.setViewport({ width: 1440, height: 950 });
        await page.click('.account-trigger');
        await click('form[action$="/logout"] button');
        await assertLocale(selected);
        report.checks.push(role + ' selection applies across three workspace menus and logout; admin retains MFA');
    }

    await go('/');
    await page.mouse.move(0, 0);
    await page.evaluate(() => scrollTo({ top: document.body.scrollHeight, behavior: 'instant' }));
    await pause(450);
    await resetWork();
    await page.evaluate(async () => {
        const bottom = scrollY;
        for (let index = 0; index < 24; index++) {
            scrollTo({ top: bottom - (index % 2) * 12, behavior: 'instant' });
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        }
    });
    report.measurements.footerScroll = await page.evaluate(() => window.motionWork);
    assert.equal(report.measurements.footerScroll.hero, 0, 'Offscreen hero must not be measured during footer scrolling');
    assert.ok(report.measurements.footerScroll.cta > 0, 'Visible CTA still responds');
    await pause(150);
    await resetWork();
    await pause(500);
    report.measurements.idle = await page.evaluate(() => window.motionWork);
    assert.equal(report.measurements.idle.writes, 0, 'No idle scroll-style updates');
    report.checks.push('Offscreen hero avoids layout reads; visible CTA responds; no scroll-style work at idle');

    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.evaluate(() => scrollTo({ top: 0, behavior: 'instant' }));
    await pause(150);
    assert.equal(await page.$eval('.process-step', el => getComputedStyle(el).translate), 'none');
    await click('.site-header a[href$="/jobs"]');
    assert.equal(await page.evaluate(() => window.nativeTransition), false);
    report.checks.push('Reduced motion disables scroll transforms and native cross-page animation');

    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'no-preference' }]);
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    await go('/');
    await locale('vi');
    await locale('ja');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.click('.nav-toggle');
    assert.equal(await page.$eval('.nav-toggle', el => el.getAttribute('aria-expanded')), 'true');
    await click('.site-header a[href$="/jobs"]');
    await assertLocale('ja');
    await page.screenshot({ path: output + 'mobile-ja.png', fullPage: true });
    report.checks.push('Mobile 390px auto-select/menu work in Japanese without horizontal overflow');

    await page.setJavaScriptEnabled(false);
    await go('/login');
    assert.notEqual(await page.$eval('.language-switcher button', el => getComputedStyle(el).display), 'none');
    await page.select('#site-language', 'en');
    await click('.language-switcher button');
    await assertLocale('en');
    report.checks.push('Without JavaScript, native Apply button still changes the full page');
    assert.deepEqual(report.errors, []);
    report.status = 'PASS';
} catch (error) {
    report.status = 'FAIL';
    report.failure = error.message;
    report.stack = error.stack;
    await page.screenshot({ path: output + 'failure.png', fullPage: true }).catch(() => {});
    process.exitCode = 1;
} finally {
    await writeFile(output + 'browser.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
    await browser.close();
}
